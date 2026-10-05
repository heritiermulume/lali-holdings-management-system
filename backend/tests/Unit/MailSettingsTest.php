<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Support\MailSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('settings');
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group');
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->timestamps();
            $table->unique(['group', 'key']);
        });
    }

    public function test_applies_smtp_settings_from_catalog(): void
    {
        $this->putSetting('mail_host', 'smtp.example.com');
        $this->putSetting('mail_port', '465', 'number');
        $this->putSetting('mail_username', 'mailer@example.com');
        $this->putSetting('mail_password', 'secret');
        $this->putSetting('mail_encryption', 'ssl');
        $this->putSetting('mail_from_address', 'noreply@example.com');
        $this->putSetting('mail_from_name', 'LHMS Mail');

        MailSettings::apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('mailer@example.com', config('mail.mailers.smtp.username'));
        $this->assertSame('secret', config('mail.mailers.smtp.password'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('noreply@example.com', config('mail.from.address'));
        $this->assertSame('LHMS Mail', config('mail.from.name'));
    }

    public function test_leaves_default_mailer_when_host_empty(): void
    {
        config(['mail.default' => 'array']);

        $this->putSetting('mail_host', '');
        $this->putSetting('mail_from_address', 'from@example.com');

        MailSettings::apply();

        $this->assertSame('array', config('mail.default'));
        $this->assertSame('from@example.com', config('mail.from.address'));
    }

    public function test_can_send_mail_with_array_transport(): void
    {
        config(['mail.default' => 'array']);
        Mail::forgetMailers();

        Mail::raw('LHMS mail works', function ($message) {
            $message->to('qa@example.com')->subject('Mail check');
        });

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();

        $this->assertCount(1, $messages);
    }

    private function putSetting(string $key, string $value, string $type = 'string'): void
    {
        Setting::query()->updateOrCreate(
            ['group' => 'general', 'key' => $key],
            ['value' => $value, 'type' => $type]
        );
    }
}
