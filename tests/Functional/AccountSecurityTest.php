<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Component\Mime\Email;

class AccountSecurityTest extends FunctionalTestCase
{
    public function testRegistrationSendsAWorkingVerificationLink(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->selectButton('Créer mon compte')->form([
            'registration_form[email]' => 'newbie@example.test',
            'registration_form[username]' => 'newbie',
            'registration_form[plainPassword]' => self::PASSWORD,
            'registration_form[agreeTerms]' => true,
        ]));
        self::assertResponseRedirects('/bienvenue');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailHeaderSame($email, 'To', 'newbie <newbie@example.test>');
        $verificationUrl = $this->extractLink($email, '/verify/email');
        $this->client->request('GET', '/forum/');
        self::assertSelectorTextContains('.alert-warning', 'Confirme ton adresse e-mail');
        $this->client->request('GET', $verificationUrl);
        self::assertResponseRedirects('/');
        $user = $this->entityManager()->getRepository(User::class)->findOneBy(['username' => 'newbie']);
        self::assertTrue($user->isVerified());
    }

    public function testForgottenPasswordFlowChangesThePasswordOnce(): void
    {
        $this->createMember('alice');
        $crawler = $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submit($crawler->selectButton('Envoyer le lien')->form(['reset_password_request_form[identifier]' => 'alice']));
        self::assertResponseRedirects('/mot-de-passe-oublie/verifier');
        self::assertEmailCount(1);
        $resetUrl = $this->extractLink(self::getMailerMessage(), '/mot-de-passe-oublie/reinitialiser/');
        $this->client->request('GET', $resetUrl);
        self::assertResponseRedirects('/mot-de-passe-oublie/reinitialiser');
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Enregistrer le mot de passe')->form([
            'change_password_form[newPassword][first]' => 'a brand new passphrase',
            'change_password_form[newPassword][second]' => 'a brand new passphrase',
        ]));
        self::assertResponseRedirects('/login');
        $this->login('alice', 'a brand new passphrase');
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->request('GET', $resetUrl);
        $this->client->followRedirect();
        self::assertResponseRedirects('/mot-de-passe-oublie');
    }

    public function testForgottenPasswordDoesNotRevealUnknownAccounts(): void
    {
        $crawler = $this->client->request('GET', '/mot-de-passe-oublie');
        $this->client->submit($crawler->selectButton('Envoyer le lien')->form(['reset_password_request_form[identifier]' => 'nobody@example.test']));
        self::assertResponseRedirects('/mot-de-passe-oublie/verifier');
        self::assertEmailCount(0);
    }

    public function testLoginIsThrottledAfterFiveFailures(): void
    {
        $this->createMember('bruteforced');
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->login('bruteforced', 'wrong password');
        }
        $this->login('bruteforced', self::PASSWORD);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Plusieurs tentatives de connexion', $crawler->filter('.alert-danger')->text());
    }

    private function login(string $identifier, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => $identifier,
            '_password' => $password,
        ]));
    }

    private function extractLink(Email $email, string $pathPrefix): string
    {
        preg_match('#href="(https?://[^"]*' . preg_quote($pathPrefix, '#') . '[^"]*)"#', (string) $email->getHtmlBody(), $matches);
        self::assertNotEmpty($matches, 'Link not found in the e-mail.');
        return html_entity_decode($matches[1]);
    }
}
