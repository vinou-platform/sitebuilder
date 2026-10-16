<?php
namespace Vinou\SiteBuilder\Tools;

use \Vinou\ApiConnector\Session\Session;

/**
 * Keeps the basket across browser sessions.
 *
 * The basket UUID lives in the PHP session, which is bound to a session
 * cookie without expiry. Mobile Safari drops such cookies whenever the app
 * is closed or evicted, and the server deletes idle session files after
 * session.gc_maxlifetime (24 minutes by default) – the basket was lost.
 *
 * This class mirrors the basket UUID into its own long-lived, HttpOnly
 * cookie and restores it into a fresh session. Api::initBasket() validates
 * the restored UUID against the API and creates a new basket if it is gone.
 * After a successful order the cookie is removed together with the session
 * basket, so a finished basket is never restored.
 */
class BasketCookie {

    public const NAME = 'vinou_basket';

    public const LIFETIME_DAYS = 30;

    /** Session flag: the basket was closed by an order in this session. */
    private const CLEARED_FLAG = 'basketCookieCleared';

    /**
     * Puts the basket UUID from the cookie into the session if the session
     * has none. Call before Api::initBasket().
     */
    public static function restore(): void {
        if (Session::getValue('basket') || Session::getValue(self::CLEARED_FLAG))
            return;

        $uuid = self::cookieValue();
        if ($uuid !== null)
            Session::setValue('basket', $uuid);
    }

    /**
     * Writes the current session basket into the cookie and renews its
     * lifetime. Must run before any output is sent.
     */
    public static function sync(): void {
        $uuid = Session::getValue('basket');
        if (!$uuid || headers_sent())
            return;

        Session::deleteValue(self::CLEARED_FLAG);
        self::send((string)$uuid, time() + self::LIFETIME_DAYS * 86400);
        $_COOKIE[self::NAME] = (string)$uuid;
    }

    /**
     * Removes the cookie after the basket was turned into an order.
     */
    public static function clear(): void {
        Session::setValue(self::CLEARED_FLAG, true);
        unset($_COOKIE[self::NAME]);

        if (!headers_sent())
            self::send('', time() - 3600);
    }

    /**
     * Returns the basket UUID from the request cookie, or null if it is
     * missing or malformed.
     */
    private static function cookieValue(): ?string {
        $value = $_COOKIE[self::NAME] ?? null;
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $value))
            return null;

        return $value;
    }

    private static function send(string $value, int $expires): void {
        setcookie(self::NAME, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function isHttps(): bool {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            return true;

        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
