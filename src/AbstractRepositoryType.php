<?php

declare(strict_types=1);

namespace Codedge\Updater;

use Codedge\Updater\Events\HasWrongPermissions;
use File;
use GuzzleHttp\Client;
use Symfony\Component\Finder\Finder;

/**
 * AbstractRepositoryType.php.
 *
 * @author Holger Lösken <holger.loesken@codedge.de>
 * @copyright See LICENSE file that was distributed with this source code.
 */
abstract class AbstractRepositoryType
{
    const ACCESS_TOKEN_PREFIX = 'Bearer ';

    /**
     * @var array
     */
    protected $config;

    /**
     * Access token for private repository access.
     */
    private $accessToken = '';

    /**
     * @var Finder|SplFileInfo[]
     */
    protected $pathToUpdate;

    /**
     * Unzip an archive.
     *
     * @param string $file
     * @param string $targetDir
     * @param bool   $deleteZipArchive
     *
     * @throws \Exception
     *
     * @return bool
     */
    protected function unzipArchive($file = '', $targetDir = '', $deleteZipArchive = true) : bool
    {
        if (empty($file) || ! File::exists($file)) {
            throw new \InvalidArgumentException("Archive [{$file}] cannot be found or is empty.");
        }

        $zip = new \ZipArchive();
        $res = $zip->open($file);

        if (! $res) {
            throw new \Exception("Cannot open zip archive [{$file}].");
        }

        if (empty($targetDir)) {
            $extracted = $zip->extractTo(File::dirname($file));
        } else {
            $extracted = $zip->extractTo($targetDir);
        }

        $zip->close();

        if ($extracted && $deleteZipArchive === true) {
            File::delete($file);
        }

        return true;
    }

    /**
     * Check a given directory recursively if all files are writeable.
     *
     * @throws \Exception
     *
     * @return bool
     */
    protected function hasCorrectPermissionForUpdate() : bool
    {
        if (! $this->pathToUpdate) {
            throw new \Exception('No directory set for update. Please set the update with: setPathToUpdate(path).');
        }

        $collection = collect($this->pathToUpdate->files())->each(function ($file) { /* @var \SplFileInfo $file */
            if (! File::isWritable($file->getRealPath())) {
                event(new HasWrongPermissions($this));

                return false;
            }
        });

        return true;
    }

    /**
     * Download a file to a given location.
     *
     * @param Client $client
     * @param string $source
     * @param string $storagePath
     *
     * @return mixed|\Psr\Http\Message\ResponseInterface
     */
    protected function downloadRelease(Client $client, $source, $storagePath)
    {
        $headers = [];

        if ($this->hasAccessToken()) {
            $headers = [
                'Authorization' => $this->getAccessToken(),
            ];
        }

        return $client->request(
            'GET',
            $source,
            [
                'sink' => $storagePath,
                'headers' => $headers,
            ]
        );
    }

    /**
     * Check if the source has already been downloaded.
     *
     * @param string $version A specific version
     *
     * @return bool
     */
    protected function isSourceAlreadyFetched($version) : bool
    {
        $storagePath = $this->config['download_path'].'/'.$version;
        if (! File::exists($storagePath) || empty(File::directories($storagePath))
        ) {
            return false;
        }

        return true;
    }

    /**
     * Set the paths to be updated.
     *
     * @param string $path    Path where the update should be run into
     * @param array  $exclude List of folder names that shall not be updated
     */
    protected function setPathToUpdate(string $path, array $exclude)
    {
        $finder = (new Finder())->in($path)->exclude($exclude);

        $this->pathToUpdate = $finder;
    }

    /**
     * Create a releas sub-folder inside the storage dir.
     *
     * @param string $storagePath
     * @param string $releaseName
     */
    public function createReleaseFolder($storagePath, $releaseName)
    {
        $subDirName = File::directories($storagePath);
        $directories = File::directories($subDirName[0]);

        File::makeDirectory($storagePath.'/'.$releaseName);

        foreach ($directories as $directory) { /* @var string $directory */
            File::moveDirectory($directory, $storagePath.'/'.$releaseName.'/'.File::name($directory));
        }

        $files = File::allFiles($subDirName[0], true);
        foreach ($files as $file) { /* @var \SplFileInfo $file */
            File::move($file->getRealPath(), $storagePath.'/'.$releaseName.'/'.$file->getFilename());
        }

        File::deleteDirectory($subDirName[0]);
    }

    /**
     * Get the access token.
     *
     * @param bool $withPrefix
     *
     * @return string
     */
    public function getAccessToken($withPrefix = true): string
    {
        if ($withPrefix) {
            return self::ACCESS_TOKEN_PREFIX.$this->accessToken;
        }

        return $this->accessToken;
    }

    /**
     * Set access token.
     *
     * @param string $token
     */
    public function setAccessToken(string $token): void
    {
        $this->accessToken = $token;
    }

    /**
     * Check if an access token has been set.
     *
     * @return bool
     */
    public function hasAccessToken(): bool
    {
        return ! empty($this->accessToken);
    }
}
