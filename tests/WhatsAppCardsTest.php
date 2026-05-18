<?php

namespace BootDesk\ChatSDK\WhatsApp\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\WhatsApp\WhatsAppCards;
use PHPUnit\Framework\TestCase;

class WhatsAppCardsTest extends TestCase
{
    public function test_interactive_message_with_buttons(): void
    {
        $card = Card::make()
            ->header('Choose')
            ->section(fn ($s) => $s->text('Pick one'))
            ->actions([
                Button::primary('Yes', 'yes'),
                Button::danger('No', 'no'),
            ]);

        $interactive = WhatsAppCards::toInteractiveMessage($card);

        $this->assertNotNull($interactive);
        $this->assertSame('button', $interactive['type']);
        $this->assertSame('Choose', $interactive['header']['text']);
        $this->assertCount(2, $interactive['action']['buttons']);
    }

    public function test_too_many_buttons_returns_null(): void
    {
        $card = Card::make()->actions([
            Button::secondary('A', 'a'),
            Button::secondary('B', 'b'),
            Button::secondary('C', 'c'),
            Button::secondary('D', 'd'),
        ]);

        $this->assertNull(WhatsAppCards::toInteractiveMessage($card));
    }

    public function test_card_to_text_fallback(): void
    {
        $card = Card::make()
            ->header('Deploy')
            ->section(fn ($s) => $s->text('Build passed'))
            ->actions([Button::primary('Go', 'go')]);

        $text = WhatsAppCards::cardToText($card);
        $this->assertStringContainsString('*Deploy*', $text);
        $this->assertStringContainsString('Build passed', $text);
        $this->assertStringContainsString('[Go]', $text);
    }

    public function test_encode_decode_callback_data(): void
    {
        $encoded = WhatsAppCards::encodeCallbackData('approve', '123');
        $decoded = WhatsAppCards::decodeCallbackData($encoded);

        $this->assertSame('approve', $decoded['actionId']);
        $this->assertSame('123', $decoded['value']);
    }

    public function test_decode_null_returns_default(): void
    {
        $decoded = WhatsAppCards::decodeCallbackData(null);
        $this->assertSame('whatsapp_callback', $decoded['actionId']);
    }

    public function test_non_prefixed_data(): void
    {
        $decoded = WhatsAppCards::decodeCallbackData('plain_text');
        $this->assertSame('plain_text', $decoded['actionId']);
    }

    public function test_card_to_text_with_fields(): void
    {
        $card = Card::make()
            ->header('Details')
            ->section(fn ($s) => $s->fields(['Name' => 'John', 'Age' => '30']));

        $text = WhatsAppCards::cardToText($card);
        $this->assertStringContainsString('*Details*', $text);
        $this->assertStringContainsString('Name:', $text);
        $this->assertStringContainsString('John', $text);
    }

    public function test_card_with_header_interactive(): void
    {
        $card = Card::make()
            ->header('Confirm')
            ->actions([Button::primary('Yes', 'yes')]);

        $interactive = WhatsAppCards::toInteractiveMessage($card);
        $this->assertNotNull($interactive);
        $this->assertSame('Confirm', $interactive['header']['text']);
    }

    public function test_card_without_header_interactive(): void
    {
        $card = Card::make()
            ->section(fn ($s) => $s->text('Body only'))
            ->actions([Button::secondary('Ok', 'ok')]);

        $interactive = WhatsAppCards::toInteractiveMessage($card);
        $this->assertNotNull($interactive);
        $this->assertArrayNotHasKey('header', $interactive);
    }

    public function test_decode_invalid_json_fallback(): void
    {
        $decoded = WhatsAppCards::decodeCallbackData('chat:invalid');
        $this->assertSame('chat:invalid', $decoded['actionId']);
    }

    public function test_encode_decode_with_value(): void
    {
        $encoded = WhatsAppCards::encodeCallbackData('reject', 'reason');
        $decoded = WhatsAppCards::decodeCallbackData($encoded);
        $this->assertSame('reject', $decoded['actionId']);
        $this->assertSame('reason', $decoded['value']);
    }

    public function test_long_button_label_returns_null(): void
    {
        $card = Card::make()
            ->header('Order')
            ->section(fn ($s) => $s->fields(['Item' => 'Widget', 'Qty' => '2']));

        $text = WhatsAppCards::cardToText($card);
        $this->assertStringContainsString('*Order*', $text);
        $this->assertStringContainsString('Item:', $text);
        $this->assertStringContainsString('Widget', $text);
    }

    public function test_interactive_with_section_text(): void
    {
        $card = Card::make()
            ->section(fn ($s) => $s->text('Pick one'))
            ->actions([Button::secondary('A', 'a'), Button::secondary('B', 'b')]);

        $interactive = WhatsAppCards::toInteractiveMessage($card);
        $this->assertNotNull($interactive);
        $this->assertSame('Pick one', $interactive['body']['text']);
    }

    public function test_interactive_with_fields(): void
    {
        $card = Card::make()
            ->section(fn ($s) => $s->fields(['Name' => 'John']))
            ->actions([Button::secondary('Ok', 'ok')]);

        $interactive = WhatsAppCards::toInteractiveMessage($card);
        $this->assertNotNull($interactive);
        $this->assertStringContainsString('Name:', $interactive['body']['text']);
    }
}
