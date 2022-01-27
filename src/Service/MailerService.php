<?php
/**
 * Created by PhpStorm.
 * User: Emanuel
 * Date: 03.10.2019
 * Time: 19:01
 */

namespace App\Service;


use App\Entity\Rooms;
use App\Entity\Server;
use App\Entity\User;
use App\UtilsHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Transports;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MailerService
{


    private $swift;
    private $parameter;
    private $kernel;
    private $logger;
    private $customMailer;
    private $userName;
    private $licenseService;
    private $mailer;

    public function __construct(MailerInterface $mailer, LicenseService $licenseService, LoggerInterface $logger, ParameterBagInterface $parameterBag, \Swift_Mailer $swift_Mailer, KernelInterface $kernel)
    {
        $this->swift = $swift_Mailer;
        $this->parameter = $parameterBag;
        $this->kernel = $kernel;
        $this->logger = $logger;
        $this->customMailer = null;
        $this->userName = null;
        $this->licenseService = $licenseService;
        $this->mailer = $mailer;
    }

    public function buildTransport(Server $server)
    {

        if ($server->getSmtpHost()) {
            $this->logger->info('Build new Transport: ' . $server->getSmtpHost());
            $tmpTransport = (new \Swift_SmtpTransport(
                $server->getSmtpHost(),
                $server->getSmtpPort(),
                $server->getSmtpEncryption()))
                ->setUsername($server->getSmtpUsername())
                ->setPassword($server->getSmtpPassword());
            $tmpMailer = new \Swift_Mailer($tmpTransport);
            if ($this->userName != $server->getSmtpUsername()) {
                $this->userName = $server->getSmtpUsername();
                $this->logger->info('The Transport is new and we take him');
                $this->customMailer = $tmpMailer;
            }
            return $tmpMailer;
        }
        return false;
    }
    public function buildTransportMailer(Server $server)
    {

        if ($server->getSmtpHost()) {
            $this->logger->info('Build new Transport: ' . $server->getSmtpHost());
            $dsn = 'smtp://'.$server->getSmtpUsername().':'.$server->getSmtpPassword().'@'.$server->getSmtpHost().':'.$server->getSmtpPort();
            $transport = Transport::fromDsn($dsn);
            $tmpMailer = new Mailer($transport);

            if ($this->userName != $server->getSmtpUsername()) {
                $this->userName = $server->getSmtpUsername();
                $this->logger->info('The Transport is new and we take him');
                $this->customMailer = $tmpMailer;
            }
            return $tmpMailer;
        }
        return false;
    }
    public function sendEmail(User $user, $betreff, $content, Server $server, $replyTo = null, Rooms $rooms = null, $attachment = array()): bool
    {
        $to = $user->getEmail();
        if ($user->getLdapUserProperties() && filter_var($to, FILTER_VALIDATE_EMAIL) == false) {
            $this->logger->debug('We sent no email, because the User is an LDAP User and the email is not a valid Email');
            return true;
        }
        if ($this->parameter->get('DISALLOW_ALL_EMAILS') === 1) {
            $this->logger->debug('We don`t send emails at all so we  dont send any emails here');
            return true;
        }
        try {
            $this->logger->info('Mail To: ' . $to);
            //$res = $this->sendViaSwiftMailer($to, $betreff, $content, $server, $replyTo, $rooms, $attachment);
            $res = $this->sendViaMailer($to, $betreff, $content, $server, $replyTo, $rooms, $attachment);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $res = false;
        }
        return $res;
    }

    private function sendViaMailer($to, $betreff, $content, Server $server, $replyTo = null, Rooms $rooms = null, $attachment = array()): bool
    {


        $this->buildTransportMailer($server);
        dump($server);
        if ($server->getSmtpHost() && $this->licenseService->verify($server)) {
            $this->logger->info($server->getSmtpEmail());
            $sender = $server->getSmtpEmail();
            $senderName = $server->getSmtpSenderName();
        } else {
            $sender = $this->parameter->get('registerEmailAdress');
            $senderName = $this->parameter->get('registerEmailName');
        }
        if ($this->parameter->get('emailSenderIsModerator')) {
            $senderName = $rooms->getModerator()->getFirstName() . ' ' . $rooms->getModerator()->getLastName();
        }
        $email = (new Email())
            ->to($to)
            ->from(new Address($sender, $senderName))
            ->subject($betreff)
            ->html($content);
        if ($replyTo) {
            if (filter_var($replyTo, FILTER_VALIDATE_EMAIL) == true) {
                $email->replyTo($replyTo);
            }
        }
        foreach ($attachment as $data) {
            $email->attach($data['body'], UtilsHelper::slugify($data['filename']), $data['type']);
        };
        if ($this->kernel->getEnvironment() === 'dev') {
            $email->to($this->parameter->get('delivery_addresses'));
        }

//        try {
        dump($email);

            if ($server->getSmtpHost()) {
                $this->logger->info('Send from Custom Mailer');
                $this->customMailer->send($email);
            } else {
                $this->mailer->send($email);
            }
//        } catch (\Exception $e) {
//            $this->mailer->send($email);
//            $this->logger->error($e->getMessage());
//            throw $e;
//        }
        return true;
    }

    private function sendViaSwiftMailer($to, $betreff, $content, Server $server, $replyTo = null, Rooms $rooms = null, $attachment = array()): bool
    {
        $this->buildTransport($server);
        if ($server->getSmtpHost() && $this->licenseService->verify($server)) {
            $this->logger->info($server->getSmtpEmail());
            $sender = $server->getSmtpEmail();
            $senderName = $server->getSmtpSenderName();
        } else {
            $sender = $this->parameter->get('registerEmailAdress');
            $senderName = $this->parameter->get('registerEmailName');
        }
        if ($this->parameter->get('emailSenderIsModerator')) {
            $senderName = $rooms->getModerator()->getFirstName() . ' ' . $rooms->getModerator()->getLastName();
        }
        $message = (new \Swift_Message($betreff))
            ->setFrom(array($sender => $senderName))
            ->setTo($to)
            ->setBody(
                $content
                , 'text/html'
            );

        if ($replyTo) {
            if (filter_var($replyTo, FILTER_VALIDATE_EMAIL) == true) {
                $message->setReplyTo($replyTo);
            }
        }
        foreach ($attachment as $data) {
            $message->attach(new \Swift_Attachment($data['body'], UtilsHelper::slugify($data['filename']), $data['type']));
        };

        try {
            if ($server->getSmtpHost()) {
                if ($this->kernel->getEnvironment() === 'dev') {
                    $message->setTo($this->parameter->get('delivery_addresses'));
                }
                $this->logger->info('Send from Custom Mailer');
                $this->customMailer->send($message);
            } else {
                $this->swift->send($message);
            }
        } catch (\Exception $e) {
            $this->swift->send($message);
            $this->logger->error($e->getMessage());
            throw $e;
        }
        return true;
    }
}
