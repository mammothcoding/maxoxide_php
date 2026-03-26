<?php

declare(strict_types=1);

namespace Maxoxide;

/**
 * Minimal webhook receiver for the Max Bot API.
 *
 * Does NOT require any framework — works with plain PHP-FPM / Apache.
 *
 * How it works:
 *   1. Your bot registers a webhook via Bot::subscribe().
 *   2. Max sends POST requests to your HTTPS endpoint.
 *   3. WebhookReceiver verifies the optional secret, parses the Update
 *      and passes it to your Dispatcher.
 *
 * Requirements from Max API:
 *   - Endpoint reachable over HTTPS on port 443.
 *   - No self-signed certificates.
 *   - Must return HTTP 200 within 30 seconds.
 *
 * Usage in your webhook entry point (e.g. webhook.php):
 *
 *   <?php
 *   require 'vendor/autoload.php';
 *
 *   $bot = new \Maxoxide\Bot('your_token');
 *   $dp  = new \Maxoxide\Dispatcher($bot);
 *
 *   $dp->onCommand('/start', function (\Maxoxide\Context $ctx) {
 *       $ctx->bot->sendTextToChat($ctx->update->message->chatId(), 'Hello!');
 *   });
 *
 *   \Maxoxide\WebhookReceiver::handle($dp, secret: 'my_secret_123');
 */
class WebhookReceiver
{
    /**
     * Handle one incoming webhook request.
     *
     * Call this at the very end of your webhook entry script.
     * Returns immediately after dispatching (does not loop).
     *
     * @param Dispatcher  $dispatcher  Your configured dispatcher.
     * @param string|null $secret      The shared secret set in SubscribeBody::$secret.
     *                                 When provided, any request with a wrong or missing
     *                                 X-Max-Bot-Api-Secret header is rejected with 401.
     */
    public static function handle(Dispatcher $dispatcher, ?string $secret = null): void
    {
        // ── 1. Verify secret ────────────────────────────────────────────────
        if ($secret !== null) {
            $provided = $_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? '';
            if ($provided !== $secret) {
                http_response_code(401);
                echo 'Unauthorized';
                return;
            }
        }

        // ── 2. Only accept POST ──────────────────────────────────────────────
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo 'Method Not Allowed';
            return;
        }

        // ── 3. Read & parse the request body ────────────────────────────────
        $raw  = file_get_contents('php://input');
        $data = json_decode((string) $raw, true);

        if (!is_array($data)) {
            // Return 200 so Max does not retry a permanently malformed payload.
            http_response_code(200);
            return;
        }

        // ── 4. Dispatch ──────────────────────────────────────────────────────
        try {
            $update = Update::fromArray($data);
            $dispatcher->dispatch($update);
        } catch (\Throwable $e) {
            // Log but still return 200 to avoid Max retrying indefinitely.
            fwrite(STDERR, "[maxoxide] Webhook dispatch error: {$e->getMessage()}\n");
        }

        http_response_code(200);
    }
}
