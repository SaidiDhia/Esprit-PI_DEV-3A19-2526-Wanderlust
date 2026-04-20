<?php
require 'vendor/autoload.php';
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Email;

try {
    $transport = Transport::fromDsn('gmail+smtp://wanderlusttunisie582@gmail.com:xemxduhlekwabvoo@default');
    $mailer = new Mailer($transport);
    $email = (new Email())
        ->from('hello@wanderlust.com')
        ->to('wanderlusttunisie582@gmail.com')
        ->subject('Time for Symfony Mailer!')
        ->text('Sending emails is fun again!');
    
    $mailer->send($email);
    echo "Email Sent Successfully!\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
