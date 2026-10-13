<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\VideoProcessingException;
use App\Services\Media\Video\HostFfmpeg;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HostFfmpegTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.video_extraction.host_ffmpeg.url', 'http://host.docker.internal:2024/');
    }

    #[Test]
    public function it_sends_the_arguments_unescaped_and_returns_ffmpegs_output(): void
    {
        Http::fake(['host.docker.internal:2024/run' => Http::response(['exit_code' => 0, 'output' => 'encoded'])]);

        $output = app(HostFfmpeg::class)->run(['-i', "/mnt/historic-work/a b's.mp4", '-y', '/mnt/historic-work/out.mp4'], 'paired section encode');

        $this->assertSame('encoded', $output);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://host.docker.internal:2024/run'
            && $request['arguments'] === ['-i', "/mnt/historic-work/a b's.mp4", '-y', '/mnt/historic-work/out.mp4']);
    }

    #[Test]
    public function a_failed_encode_reports_ffmpegs_output(): void
    {
        Http::fake(['*' => Http::response(['exit_code' => 1, 'output' => 'Error while opening encoder'])]);

        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('FFmpeg paired section encode failed: Error while opening encoder');

        app(HostFfmpeg::class)->run(['-version'], 'paired section encode');
    }

    #[Test]
    public function an_unreachable_host_fails_the_encode_instead_of_encoding_elsewhere(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('host encoder unreachable at http://host.docker.internal:2024/run');

        app(HostFfmpeg::class)->run(['-version'], 'paired section encode');
    }

    #[Test]
    public function a_refused_request_reports_the_reason(): void
    {
        Http::fake(['*' => Http::response(['error' => 'path outside the mapped roots: /etc/passwd'], 400)]);

        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('host encoder refused the request (HTTP 400): path outside the mapped roots: /etc/passwd');

        app(HostFfmpeg::class)->run(['-i', '/etc/passwd'], 'paired section encode');
    }

    #[Test]
    public function the_host_encoder_must_be_configured(): void
    {
        Config::set('media-processing.video_extraction.host_ffmpeg.url', null);
        Http::fake();

        $this->expectException(VideoProcessingException::class);
        $this->expectExceptionMessage('host_ffmpeg.url is not set');

        try {
            app(HostFfmpeg::class)->run(['-version'], 'paired section encode');
        } finally {
            Http::assertNothingSent();
        }
    }
}
