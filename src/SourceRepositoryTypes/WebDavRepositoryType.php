<?php

declare(strict_types=1);

namespace Codedge\Updater\SourceRepositoryTypes;

use File;
use Storage;
use Sabre\DAV\Client;
use Symfony\Component\Finder\Finder;
use Psr\Http\Message\ResponseInterface;
use Codedge\Updater\Events\UpdateFailed;
use Codedge\Updater\Events\UpdateAvailable;
use Codedge\Updater\Events\UpdateSucceeded;
use Illuminate\Database\Eloquent\Collection;
use Codedge\Updater\AbstractRepositoryType;
use Codedge\Updater\Contracts\SourceRepositoryTypeContract;

/**
 * WebDavRepositoryType.php.
 *
 * @author Martin Niehoff <info@martin-niehoff.de>
 * @copyright See LICENSE file that was distributed with this source code.
 */
class WebDavRepositoryType extends AbstractRepositoryType implements SourceRepositoryTypeContract
{
    const NEW_VERSION_FILE = 'self-updater-new-version';

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Version prepend string
     */
    protected $prepend;

    /**
     * @var Version append string
     */
    protected $append;

    /**
     * WebDav constructor.
     *
     * @param Client $client
     * @param array $config
     */
    public function __construct(Client $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;

        $this->config['version_installed'] = config('self-update.version_installed');
        $this->config['exclude_folders'] = config('self-update.exclude_folders');

        // Get prepend and append strings
        $this->prepend = preg_replace('/_VERSION_.*$/', '', $this->config['pkg_filename_format']);
        $this->append = preg_replace('/^.*_VERSION_/', '', $this->config['pkg_filename_format']);
    }

    /**
     * Check repository if a newer version than the installed one is available.
     *
     * @param string $currentVersion
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     *
     * @return bool
     */
    public function isNewVersionAvailable($currentVersion = ''): bool
    {
        $version = $currentVersion ?: $this->getVersionInstalled();

        if (!$version) {
            throw new \InvalidArgumentException('No currently installed version specified.');
        }

        // Remove the version file to forcefully update current version
        $this->deleteVersionFile();

        if (version_compare($version, $this->getVersionAvailable(), '<')) {
            if (!$this->versionFileExists()) {
                $this->setVersionFile($this->getVersionAvailable());
                event(new UpdateAvailable($this->getVersionAvailable()));
            }

            return true;
        }

        return false;
    }

    /**
     * Fetches the latest version. If you do not want the latest version, specify one and pass it.
     *
     * @param string $version
     *
     * @throws \Exception
     *
     * @return mixed
     */
    public function fetch($version = '')
    {
        if (($releaseCollection = $this->getPackageReleases())->isEmpty()) {
            throw new \Exception('Cannot find a release to update. Please check the repository you\'re pulling from');
        }

        $release = $releaseCollection->first();
        $storagePath = $this->config['download_path'];

        if (!File::exists($storagePath)) {
            File::makeDirectory($storagePath, 493, true, true);
        }

        if (!$version) {
            $release = $releaseCollection->where('name', $version)->first();
            if (!$release) {
                throw new \Exception('Given version was not found in release list.');
            }
        }

        $versionName = $this->prepend . $release->name . $this->append;
        $storageFilename = $versionName . '.zip';

        if (!$this->isSourceAlreadyFetched($release->name)) {
            $storageFile = $storagePath . '/' . $storageFilename;
            $storageFile = str_replace('\\', '/', $storageFile);
            $storageFile = preg_replace('#/+#', '/', $storageFile);

            $request = $this->client->request('GET', $release->zipball_url);

            file_put_contents($storageFile, $request['body']);

            $this->unzipArchive($storageFile, $storagePath . '/' . $versionName);
        }
    }

    /**
     * Perform the actual update process.
     * @param string $version
     * @return bool
     * @throws \Exception
     */
    public function update($version = ''): bool
    {
        $this->setPathToUpdate(base_path(), $this->config['exclude_folders']);

        if ($this->hasCorrectPermissionForUpdate()) {
            if (empty($version)) {
                $version = $this->getVersionAvailable();
            }
            $sourcePath = $this->config['download_path'] . DIRECTORY_SEPARATOR . $this->prepend . $version . $this->append;

            // Move all directories first
            collect((new Finder())->in($sourcePath)->exclude($this->config['exclude_folders'])->directories()->sort(function (
                $a,
                $b
            ) {
                return strlen($b->getRealpath()) - strlen($a->getRealpath());
            }))->each(function ($directory) {
                /** @var \SplFileInfo $directory */
                if (count(array_intersect(File::directories(
                        $directory->getRealPath()), $this->config['exclude_folders'])) == 0) {
                    File::copyDirectory(
                        $directory->getRealPath(),
                        base_path($directory->getRelativePath()) . '/' . $directory->getBasename()
                    );
                }
                File::deleteDirectory($directory->getRealPath());
            });

            // Now move all the files left in the main directory
            collect(File::allFiles($sourcePath, true))->each(function ($file) {
                /* @var \SplFileInfo $file */
                File::copy($file->getRealPath(), base_path($file->getFilename()));
            });

            File::deleteDirectory($sourcePath);
            $this->deleteVersionFile();
            event(new UpdateSucceeded($version));

            return true;
        }

        event(new UpdateFailed($this));

        return false;
    }

    /**
     * Get the version that is currenly installed.
     * Example: 1.1.0 or v1.1.0 or "1.1.0 version".
     *
     * @param string $prepend
     * @param string $append
     *
     * @return string
     */
    public function getVersionInstalled($prepend = '', $append = ''): string
    {
        return $this->config['version_installed'];
    }

    /**
     * Get the latest version that has been published in a certain repository.
     * Example: 2.6.5 or v2.6.5.
     *
     * @param string $prepend Prepend a string to the latest version
     * @param string $append Append a string to the latest version
     *
     * @throws \Exception
     *
     * @return string
     */
    public function getVersionAvailable($prepend = '', $append = ''): string
    {
        $version = '';
        if ($this->versionFileExists()) {
            $version = $this->getVersionFile();
        } else {
            $releaseCollection = $this->getPackageReleases();
            if ($releaseCollection->isEmpty()) {
                throw new \Exception('Retrieved version list is empty.');
            }
            $version = $releaseCollection->first()->name;
        }

        return $version;
    }


    /**
     * Retrieve html body with list of all releases from archive URL.
     *
     * @throws \Exception
     *
     * @return mixed|ResponseInterface
     */
    protected function getPackageReleases()
    {
        if (empty($url = $this->config['repository_url'])) {
            throw new \Exception('No repository specified. Please enter a valid URL in your config.');
        }


        // Get a list of our repository folder's contents
        $folder_content = $this->client->propFind('', array(
            '{DAV:}getcontenttype'
        ), 1);

        // Parse our available release archives into an array
        $collection = [];
        $url = preg_replace('/\/$/', '', $url);

        foreach ($folder_content as $key => $file) {
            if (!empty($file) && $file['{DAV:}getcontenttype'] == "application/zip") {
                $basename =
                    preg_replace("/^$this->prepend/", '',
                        preg_replace("/$this->append$/", '',
                            preg_replace('/.zip$/', '', basename($key))
                        ));

                array_push($collection, (object)[
                    'name' => $basename,
                    'zipball_url' => $url . '/' . basename($key),
                ]);
            }
        }

        // Sort collection alphabetically descending to have newest package as first
        array_multisort($collection, SORT_DESC);

        return new Collection($collection);
    }

    /**
     * Check if the file with the new version already exists.
     *
     * @return bool
     */
    protected function versionFileExists(): bool
    {
        return Storage::exists(static::NEW_VERSION_FILE);
    }

    /**
     * Write the version file.
     *
     * @param $content
     *
     * @return bool
     */
    protected function setVersionFile(string $content): bool
    {
        return Storage::put(static::NEW_VERSION_FILE, $content);
    }

    /**
     * Get the content of the version file.
     *
     * @return string
     */
    protected function getVersionFile(): string
    {
        return Storage::get(static::NEW_VERSION_FILE);
    }

    /**
     * Delete the version file.
     *
     * @return bool
     */
    protected function deleteVersionFile(): bool
    {
        return Storage::delete(static::NEW_VERSION_FILE);
    }
}
