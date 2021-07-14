<?php

declare(strict_types=1);

namespace Codedge\Updater;

use Codedge\Updater\Contracts\SourceRepositoryTypeContract;
use Codedge\Updater\Contracts\UpdaterContract;
use Codedge\Updater\Models\UpdateExecutor;
use Codedge\Updater\SourceRepositoryTypes\GithubRepositoryType;
use Codedge\Updater\SourceRepositoryTypes\HttpRepositoryType;
<<<<<<< HEAD
use Codedge\Updater\SourceRepositoryTypes\WebDavRepositoryType;
use GuzzleHttp;
use Sabre\DAV;
=======
use Exception;
>>>>>>> b66304e8a51b1a0cfb8776410ab8fd1d1eb9083b
use Illuminate\Foundation\Application;
use InvalidArgumentException;

/**
 * UpdaterManager.
 *
 * @author Holger Lösken <holger.loesken@codedge.de>
 * @copyright See LICENSE file that was distributed with this source code.
 */
final class UpdaterManager implements UpdaterContract
{
    /**
     * Application instance.
     *
     * @var Application
     */
    protected $app;

    /**
     * @var array
     */
    protected $sources = [];

    /**
     * @var array
     */
    protected $customSourceCreators = [];

    /**
     * Create a new Updater manager instance.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Get a source repository type instance.
     *
     * @param string $name
     *
     * @return SourceRepositoryTypeContract
     */
<<<<<<< HEAD
    public function source($name = ''): SourceRepository
=======
    public function source(string $name = ''): SourceRepositoryTypeContract
>>>>>>> b66304e8a51b1a0cfb8776410ab8fd1d1eb9083b
    {
        $name = $name ?: $this->getDefaultSourceRepository();

        return $this->sources[$name] = $this->get($name);
    }

    /**
     * Get the default source repository type.
     *
     * @return string
     */
    public function getDefaultSourceRepository()
    {
        return $this->app['config']['self-update']['default'];
    }

    /**
     * @param SourceRepositoryTypeContract $sourceRepository
     *
<<<<<<< HEAD
     * @return SourceRepository
     */
    public function sourceRepository(SourceRepositoryTypeContract $sourceRepository)
    {
        return new SourceRepository($sourceRepository);
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @param string $source
     * @param Closure $callback
     *
     * @return $this
     */
    public function extend($source, Closure $callback)
    {
        $this->customSourceCreators[$source] = $callback;

        return $this;
    }

    /**
     * Dynamically call the default source repository instance.
     *
     * @param string $method
     * @param array $parameters
     *
     * @return mixed
=======
     * @return SourceRepositoryTypeContract
>>>>>>> b66304e8a51b1a0cfb8776410ab8fd1d1eb9083b
     */
    public function sourceRepository(SourceRepositoryTypeContract $sourceRepository): SourceRepositoryTypeContract
    {
        return new SourceRepository($sourceRepository, $this->app->make(UpdateExecutor::class));
    }

    /**
     * Get the source repository connection configuration.
     *
     * @param string $name
     *
     * @return array
     */
    protected function getConfig(string $name): array
    {
        if (isset($this->app['config']['self-update']['repository_types'][$name])) {
            return $this->app['config']['self-update']['repository_types'][$name];
        }

        return [];
    }

    /**
     * Attempt to get the right source repository instance.
     *
     * @param string $name
     *
     * @return SourceRepositoryTypeContract
     */
    protected function get(string $name)
    {
        return isset($this->sources[$name]) ? $this->sources[$name] : $this->resolve($name);
    }

    /**
     * Try to find the correct source repository implementation ;-).
     *
     * @param string $name
     *
     * @throws InvalidArgumentException
     *
     * @return SourceRepositoryTypeContract
     */
    protected function resolve(string $name): SourceRepositoryTypeContract
    {
        $config = $this->getConfig($name);

        if (empty($config)) {
            throw new InvalidArgumentException("Source repository [{$name}] is not defined.");
        }

<<<<<<< HEAD
        if (isset($this->customSourceCreators[$config['type']])) {
            return $this->callCustomSourceCreators($config);
        }
        $repositoryMethod = 'create' . ucfirst($name) . 'Repository';
=======
        $repositoryMethod = 'create'.ucfirst($name).'Repository';
>>>>>>> b66304e8a51b1a0cfb8776410ab8fd1d1eb9083b

        return $this->{$repositoryMethod}();
    }

    /**
     * @return SourceRepositoryTypeContract
     * @throws Exception
     */
    protected function createGithubRepository(): SourceRepositoryTypeContract
    {
        /** @var GithubRepositoryType $factory */
        $factory = $this->app->make(GithubRepositoryType::class);

        return $this->sourceRepository($factory->create());
    }

    /**
     * Create an instance for the Http source repository.
     *
<<<<<<< HEAD
     * @param array $config
     *
     * @return SourceRepository
     */
    protected function createHttpRepository(array $config)
    {
        $client = new GuzzleHttp\Client();

        return $this->sourceRepository(new HttpRepositoryType($client, $config));
    }

    /**
     * Create an instance for the WebDav source repository.
     *
     * @param array $config
     *
     * @return SourceRepository
     */
    protected function createWebDavRepository(array $config)
    {
        $settings = array(
            'baseUri' => $config['repository_url'],
            'userName' => $config['user'],
            'password' => $config['password']
        );

        $client = new DAV\Client($settings);

        return $this->sourceRepository(new WebDavRepositoryType($client, $config));
    }

    /**
     * Call a custom source repository type.
     *
     * @param array $config
     *
     * @return mixed
=======
     * @return SourceRepositoryTypeContract
>>>>>>> b66304e8a51b1a0cfb8776410ab8fd1d1eb9083b
     */
    protected function createHttpRepository(): SourceRepositoryTypeContract
    {
        return $this->sourceRepository($this->app->make(HttpRepositoryType::class));
    }
}
