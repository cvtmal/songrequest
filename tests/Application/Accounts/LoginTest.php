<?php

declare(strict_types=1);

namespace App\Tests\Application\Accounts;

use App\Accounts\Entity\User;
use App\Factory\AccountFactory;
use App\Factory\UserFactory;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class LoginTest extends WebTestCase
{
    // Must be outside private_ranges (trusted proxies), which include the TEST-NET ranges.
    private const string IP = '84.75.12.34';

    public function test_login_redirects_to_dj_home(): void
    {
        $client = $this->client();
        $this->dj();

        $this->logIn($client, 'dj@example.com', 'password');

        self::assertResponseRedirects('/dj');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'DJ Mira');
    }

    public function test_email_is_matched_in_any_case(): void
    {
        $client = $this->client();
        $this->dj();

        $this->logIn($client, ' DJ@Example.com', 'password');

        self::assertResponseRedirects('/dj');
    }

    public function test_wrong_password_shows_error_and_keeps_email(): void
    {
        $client = $this->client();
        $this->dj();

        $this->logIn($client, 'dj@example.com', 'wrong password');

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.form-error', 'E-Mail oder Passwort stimmt nicht.');
        self::assertInputValueSame('_username', 'dj@example.com');
    }

    public function test_unknown_email_shows_the_same_error(): void
    {
        $client = $this->client();
        $this->dj();

        $this->logIn($client, 'nobody@example.com', 'password');

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.form-error', 'E-Mail oder Passwort stimmt nicht.');
    }

    public function test_post_without_origin_fails_csrf(): void
    {
        $client = $this->client();
        $this->dj();

        // No GET first, so the POST carries neither Origin nor Referer.
        $client->request('POST', '/login', [
            '_username' => 'dj@example.com',
            '_password' => 'password',
            '_csrf_token' => 'csrf-token',
        ]);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.form-error', 'Ungültiges CSRF-Token.');
        $client->request('GET', '/dj');
        self::assertResponseRedirects('/login');
    }

    public function test_sixth_attempt_within_a_minute_is_throttled_even_with_the_right_password(): void
    {
        $client = $this->client();
        $this->dj();

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->logIn($client, 'dj@example.com', 'wrong password');
            $client->followRedirect();
            self::assertSelectorTextContains('.form-error', 'E-Mail oder Passwort stimmt nicht.', 'Attempt '.$attempt);
        }

        $this->logIn($client, 'dj@example.com', 'password');

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.form-error', 'Zu viele Fehlversuche. Versuch es in einer Minute nochmal.');
    }

    public function test_remember_me_is_on_by_default_and_survives_a_lost_session(): void
    {
        $client = $this->client();
        $this->dj();

        $this->logIn($client, 'dj@example.com', 'password');

        $jar = $client->getCookieJar();
        self::assertNotNull($jar->get('REMEMBERME'));
        foreach ($jar->all() as $cookie) {
            if ('REMEMBERME' !== $cookie->getName()) {
                $jar->expire($cookie->getName());
            }
        }
        self::assertCount(1, $jar->all());

        $client->request('GET', '/dj');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'DJ Mira');
    }

    public function test_unticked_remember_me_sets_no_cookie(): void
    {
        $client = $this->client();
        $this->dj();

        $this->logIn($client, 'dj@example.com', 'password', remember: false);

        self::assertResponseRedirects('/dj');
        self::assertNull($client->getCookieJar()->get('REMEMBERME'));
    }

    public function test_logged_in_user_visiting_login_goes_to_dj_home(): void
    {
        $client = $this->client();
        $client->loginUser($this->dj());

        $client->request('GET', '/login');

        self::assertResponseRedirects('/dj');
    }

    public function test_logout_ends_the_session(): void
    {
        $client = $this->client();
        $client->loginUser($this->dj());
        $client->request('GET', '/dj');

        $client->submitForm('Abmelden');

        self::assertResponseRedirects('/login');
        $client->request('GET', '/dj');
        self::assertResponseRedirects('/login');
    }

    public function test_logout_by_get_without_token_is_rejected(): void
    {
        $client = $this->client();
        $client->loginUser($this->dj());

        $client->request('GET', '/logout');

        self::assertFalse($client->getResponse()->isRedirect('http://localhost/login'));
        $client->request('GET', '/dj');
        self::assertResponseIsSuccessful();
    }

    private function client(): KernelBrowser
    {
        $client = self::createClient();
        $client->setServerParameter('HTTP_X_FORWARDED_FOR', self::IP);
        // The limiter pool lives on the filesystem and survives across tests. Clear it after createClient():
        // booting the kernel first would make createClient() throw.
        $pool = $client->getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();

        return $client;
    }

    private function dj(): User
    {
        return UserFactory::createOne([
            'email' => 'dj@example.com',
            'account' => AccountFactory::new(['stageName' => 'DJ Mira']),
        ]);
    }

    /**
     * GETs the page first, so the POST carries a same-origin Referer for stateless CSRF.
     */
    private function logIn(KernelBrowser $client, string $email, string $password, bool $remember = true): void
    {
        $client->request('GET', '/login');
        $form = $client->getCrawler()->selectButton('Anmelden')->form([
            '_username' => $email,
            '_password' => $password,
        ]);
        if (!$remember) {
            $checkbox = $form['_remember_me'];
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            $checkbox->untick();
        }
        $client->submit($form);
    }
}
