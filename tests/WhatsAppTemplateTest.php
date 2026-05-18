<?php

namespace BootDesk\ChatSDK\WhatsApp\Tests;

use BootDesk\ChatSDK\WhatsApp\WhatsAppTemplate;
use PHPUnit\Framework\TestCase;

class WhatsAppTemplateTest extends TestCase
{
    public function test_create_template(): void
    {
        $tpl = WhatsAppTemplate::create('order_confirmation', 'en_US');

        $this->assertSame('order_confirmation', $tpl->getName());
        $this->assertSame('en_US', $tpl->getLanguage());
    }

    public function test_to_whatsapp_with_body_params(): void
    {
        $tpl = WhatsAppTemplate::create('welcome_message', 'pt_BR')
            ->bodyParam('João')
            ->bodyParam('#123');

        $expected = [
            'type' => 'template',
            'template' => [
                'name' => 'welcome_message',
                'language' => ['code' => 'pt_BR'],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => 'João'],
                            ['type' => 'text', 'text' => '#123'],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame($expected, $tpl->toWhatsApp());
    }

    public function test_to_whatsapp_with_header_and_body(): void
    {
        $tpl = WhatsAppTemplate::create('promo', 'en_US')
            ->headerImage('https://example.com/banner.jpg')
            ->bodyParam('50% off');

        $result = $tpl->toWhatsApp();

        $this->assertSame('header', $result['template']['components'][0]['type']);
        $this->assertSame('image', $result['template']['components'][0]['parameters'][0]['type']);
        $this->assertSame('https://example.com/banner.jpg', $result['template']['components'][0]['parameters'][0]['image']['link']);
        $this->assertSame('body', $result['template']['components'][1]['type']);
    }

    public function test_named_parameters(): void
    {
        $tpl = WhatsAppTemplate::create('order_confirmation', 'en_US')
            ->named()
            ->bodyParam('Jessica', 'first_name')
            ->bodyParam('SKBUP2-4CPIG9', 'order_number');

        $result = $tpl->toWhatsApp();

        $this->assertSame('named', $result['template']['parameter_format']);
        $this->assertSame('first_name', $result['template']['components'][0]['parameters'][0]['parameter_name']);
        $this->assertSame('Jessica', $result['template']['components'][0]['parameters'][0]['text']);
        $this->assertSame('order_number', $result['template']['components'][0]['parameters'][1]['parameter_name']);
        $this->assertSame('SKBUP2-4CPIG9', $result['template']['components'][0]['parameters'][1]['text']);
    }

    public function test_positional_parameters(): void
    {
        $tpl = WhatsAppTemplate::create('order_confirmation', 'en_US')
            ->positional()
            ->bodyParam('Jessica')
            ->bodyParam('SKBUP2-4CPIG9');

        $result = $tpl->toWhatsApp();

        $this->assertSame('positional', $result['template']['parameter_format']);
        $this->assertArrayNotHasKey('parameter_name', $result['template']['components'][0]['parameters'][0]);
    }

    public function test_default_parameters_are_positional(): void
    {
        $tpl = WhatsAppTemplate::create('test', 'en')
            ->bodyParam('Hello');

        $result = $tpl->toWhatsApp();

        $this->assertArrayNotHasKey('parameter_format', $result['template']);
        $this->assertArrayNotHasKey('parameter_name', $result['template']['components'][0]['parameters'][0]);
    }

    public function test_to_whatsapp_with_buttons(): void
    {
        $tpl = WhatsAppTemplate::create('feedback', 'en_US')
            ->bodyParam('How was your experience?')
            ->buttonParam('Great', 'great_payload', 'quick_reply');

        $result = $tpl->toWhatsApp();

        $this->assertSame('button', $result['template']['components'][1]['type']);
        $this->assertSame('great_payload', $result['template']['components'][1]['parameters'][0]['payload']);
    }

    public function test_to_string_with_body_only(): void
    {
        $tpl = WhatsAppTemplate::create('test', 'en')
            ->bodyParam('Hello')
            ->bodyParam('World');

        $this->assertSame('**Hello | World**', (string) $tpl);
    }

    public function test_to_string_with_all_components(): void
    {
        $tpl = WhatsAppTemplate::create('promo', 'pt_BR')
            ->headerImage('https://example.com/banner.jpg')
            ->bodyParam('50% off')
            ->bodyParam('code: SAVE50')
            ->buttonParam('Shop Now');

        $result = (string) $tpl;
        $this->assertStringContainsString('**50% off | code: SAVE50**', $result);
        $this->assertStringContainsString('🖼 https://example.com/banner.jpg', $result);
        $this->assertStringContainsString('1. [Shop Now]', $result);
    }

    public function test_to_string_header_text_only(): void
    {
        $tpl = WhatsAppTemplate::create('alert', 'en')
            ->headerText('System maintenance tonight')
            ->buttonParam('Dismiss');

        $result = (string) $tpl;
        $this->assertStringContainsString('System maintenance tonight', $result);
        $this->assertStringContainsString('1. [Dismiss]', $result);
    }

    public function test_to_array(): void
    {
        $tpl = WhatsAppTemplate::create('test', 'en')
            ->bodyParam('Hello');

        $this->assertSame([
            'name' => 'test',
            'language' => 'en',
            'body_params' => ['Hello'],
        ], $tpl->toArray());
    }
}
