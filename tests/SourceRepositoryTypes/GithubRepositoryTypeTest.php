<?php declare(strict_types=1);

namespace Codedge\Updater\Tests\SourceRepositoryTypes;

use Codedge\Updater\Events\UpdateAvailable;
use Codedge\Updater\SourceRepositoryTypes\GithubRepositoryType;
use Codedge\Updater\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Exception;

class GithubRepositoryTypeTest extends TestCase
{
    const GITHUB_API_URL = 'https://api.github.com';

    /**
     * @var Client;
     */
    protected $client;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var string
     */
    private $releasesAsJson;

    public function setUp(): void
    {
        parent::setUp();
        $this->config = $this->app['config']['self-update']['repository_types']['github'];
        $this->releasesAsJson = fopen('tests/Data/releases.json', 'r');

        $response = new Response(
            200,
            [
                'Content-Type' => 'application/json'
            ],
            \GuzzleHttp\Psr7\stream_for($this->releasesAsJson));

        $mock = new MockHandler([
            $response,
            $response,
            $response,
            $response
        ]);

        $handler = HandlerStack::create($mock);
        $this->client = new Client(['handler' => $handler]);
        $this->client->request(
            'GET',
            self::GITHUB_API_URL
            .'/repos/'
            .$this->config['repository_vendor'].'/'.$this->config['repository_name'].'/tags'
        );
    }

    public function testIsNewVersionAvailableFailsWithInvalidArgumentException()
    {
        $class = new GithubRepositoryType($this->client, $this->config);
        $this->expectException(InvalidArgumentException::class);
        $class->isNewVersionAvailable();
    }

    public function testIsNewVersionAvailableTriggerUpdateAvailableEvent()
    {
        $class = new GithubRepositoryType($this->client, $this->config);
        $currentVersion = 'v1.1.0';

        Storage::delete(GithubRepositoryType::NEW_VERSION_FILE);

        $this->expectsEvents(UpdateAvailable::class);
        $this->assertTrue($class->isNewVersionAvailable($currentVersion));
    }

    public function testIsNewVersionAvailable()
    {
        $class = new GithubRepositoryType($this->client, $this->config);

        $currentVersion = 'v1.1.0';
        $this->assertTrue($class->isNewVersionAvailable($currentVersion));

        $currentVersion = 'v100.1';
        $this->assertFalse($class->isNewVersionAvailable($currentVersion));

    }

    public function testGetVersionAvailable()
    {
        $class = new GithubRepositoryType($this->client, $this->config);
        $this->assertNotEmpty($class->getVersionAvailable());
        $this->assertStringStartsWith('v', $class->getVersionAvailable('v'));
        $this->assertStringEndsWith('version', $class->getVersionAvailable('', 'version'));
    }

    public function testFetchingFailsWithException()
    {
        $class = new GithubRepositoryType($this->client, $this->config);
        $this->expectException(Exception::class);
        $class->fetch();
    }

    public function testHasAccessTokenSet()
    {
        $config = $this->config;
        $config['private_access_token'] = 'abc123';

        $class = new GithubRepositoryType($this->client, $config);
        $this->assertTrue($class->hasAccessToken());
        $this->assertEquals($class->getAccessToken(), 'Bearer abc123');
    }

    public function testHasAccessTokenNotSet()
    {
        $class = new GithubRepositoryType($this->client, $this->config);
        $this->assertFalse($class->hasAccessToken());
        $this->assertEquals($class->getAccessToken(), 'Bearer ');
    }
}
