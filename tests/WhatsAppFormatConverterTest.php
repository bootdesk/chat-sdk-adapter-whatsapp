<?php

namespace BootDesk\ChatSDK\WhatsApp\Tests;

use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\WhatsApp\WhatsAppFormatConverter;
use PHPUnit\Framework\TestCase;

class WhatsAppFormatConverterTest extends TestCase
{
    private WhatsAppFormatConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new WhatsAppFormatConverter;
    }

    public function test_inbound_bold(): void
    {
        $ast = $this->converter->toAst('Hello *world*');
        $html = $this->converter->fromAst($ast);
        $this->assertStringContainsString('world', $html);
    }

    public function test_inbound_strikethrough(): void
    {
        $ast = $this->converter->toAst('Hello ~world~');
        $html = $this->converter->fromAst($ast);
        $this->assertStringContainsString('world', $html);
    }

    public function test_outbound_bold(): void
    {
        $ast = $this->converter->toAst('Hello **world**');
        $html = $this->converter->fromAst($ast);
        $this->assertStringContainsString('world', $html);
    }

    public function test_render_postable_card(): void
    {
        $card = Card::make()->header('Card')->section(fn ($s) => $s->text('Content'));
        $message = PostableMessage::card($card);

        $result = $this->converter->renderPostable($message);
        $this->assertStringContainsString('Card', $result);
    }

    public function test_render_postable_text(): void
    {
        $message = PostableMessage::text('Plain text');
        $result = $this->converter->renderPostable($message);
        $this->assertSame('Plain text', $result);
    }

    public function test_outbound_strikethrough(): void
    {
        $ast = $this->converter->toAst('Hello ~~world~~');
        $html = $this->converter->fromAst($ast);
        $this->assertStringContainsString('world', $html);
    }
}
