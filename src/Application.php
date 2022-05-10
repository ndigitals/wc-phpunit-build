<?php

namespace Build;

use Comodojo\Zip\Zip;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use PHPGit\Exception\GitException;
use PHPGit\Git;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class Application extends Container
{
    public function run()
    {
        try {
            $this->tagsToBuild()
                ->each(function (WooCommerceGitTag $tag) {
                    $this->buildTag($tag);
                })->tap(function ($built) {
                    $this->reportResults($built);
                });
        } catch (\Exception $e) {
            $this->logger()->error(
                sprintf('%s thrown: %s', get_class($e), $e->getMessage())
            );
            $this->logger()->debug($e->getTraceAsString());
            if ($e instanceof GitException) {
                $this->logger()->debug($e->getCommandLine());
            }
            exit(1);
        }
    }

    /**
     * @return Collection
     */
    protected function tagsToBuild()
    {
        return collect($this->repoWooCommerce()->tag())
            ->reject(function ($tag) {
                return version_compare($tag, '3.7', '<'); // skip tags before phpunit library was added in 3.7
            })->reject(function ($tag) {
                return collect($this->repoPackage()->tag())->contains($tag); // remove any which already exist on the target repository
            })->map(function ($tag) {
                return new WooCommerceGitTag($tag, $this->repoWooCommerce());
            })->sortBy(function (WooCommerceGitTag $tag) {
                return $tag->versionNormalized();
            })
        ;
    }

    private function buildTag(WooCommerceGitTag $tag)
    {
        $this->logger()->info("Building version $tag->name");
        $this->primeFilesystemForNewBuild();
        $this->primePackageForTag($tag);
        $this->generateSourceFiles($tag);
        $this->copySourceFiles();
        $this->copyTemplateFiles();
        $this->tagNewVersion($tag);
        $this->mergeBranchIfMajorVersion($tag);
    }

    private function primeFilesystemForNewBuild()
    {
        tap(new Filesystem(), function (Filesystem $fs) {
            if ($fs->exists($this->make('path.artifacts'))) {
                $fs->remove($this->make('path.artifacts'));
            }
        });
    }

    private function primePackageForTag(WooCommerceGitTag $tag)
    {
        $branch_exists = collect($this->repoPackage()->branch())->contains('name', $tag->majorBranchName());

        if ($tag->isDotZero() && ! $branch_exists) {
            $this->logger()->debug("Initializing branch for $tag->name");
            $this->repoPackage()->branch->create($tag->majorBranchName(), 'master');
        }

        $this->repoPackage()->checkout($tag->majorBranchName());

        (new Filesystem())->remove(
            (new Finder)->files()->in($this->make('path.repo.package'))->ignoreVCS(true)
        );
    }

    private function generateSourceFiles(WooCommerceGitTag $tag)
    {
        tap($this->make('path.artifacts'), function ($artifacts_path) use ($tag) {
            (new Filesystem())->mkdir("$artifacts_path/source/", 0755);

            $this->repoWooCommerce()->archive(
                "$artifacts_path/source.zip",
                $tag->sha() . ':tests/phpunit',
                '.',
                [
                    'format' => 'zip',
                ]
            );

            Zip::open("$artifacts_path/source.zip")
                ->extract("$artifacts_path/source/");
        });
    }

    private function copySourceFiles()
    {
        collect((new Finder)->directories()->in($this->make('path.artifacts') . '/source')->depth(0)->exclude(['tests']))
            ->each(function(SplFileInfo $dir) {
                $this->logger()->debug('Mirroring directory ' . $dir->getRelativePathname());
                
                (new Filesystem)->mirror(
                    $dir->getRealPath(),
                    $this->make('path.repo.package') . '/' . $dir->getRelativePathname()
                );
            });
    }

    private function copyTemplateFiles()
    {
        collect((new Finder)->files()->in($this->make('path.templates')))
            ->each(function(SplFileInfo $file) {
                $this->logger()->debug('Copying file ' . $file->getRelativePathname());

                (new Filesystem)->copy(
                    $file->getRealPath(),
                    $this->make('path.repo.package') . '/' . $file->getRelativePathname()
                );
            });
    }

    private function tagNewVersion(WooCommerceGitTag $tag)
    {
        $this->repoPackage()->add('.');

        // If we try to commit without any changes, a GitException will be thrown.
        // This is not a problem as not every version will have changes.
        if (0 < count($this->repoPackage()->status()['changes'])) {
            $this->repoPackage()->commit("Building $tag->name", [
                'all' => true,
            ]);
        }

        $this->repoPackage()->tag->create($tag->name);
    }

    private function mergeBranchIfMajorVersion(WooCommerceGitTag $tag)
    {
        if ($tag->isDotZero()) {
            $this->repoPackage()->checkout('master');
            $this->repoPackage()->merge($tag->majorBranchName(), null, ['no-ff' => true]);
        }
    }

    private function reportResults(Collection $built)
    {
        if ($built->isEmpty()) {
            $this->logger()->info('No new tags to be built!');
        } else {
            $this->logger()->info(sprintf('Built %s tags successfully.', $built->count()));
        }
    }

    /**
     * @return Git
     */
    public function repoPackage()
    {
        return $this->make('git.package');
    }

    /**
     * @return Git
     */
    public function repoWooCommerce()
    {
        return $this->make('git.woocommerce');
    }

    /**
     * @return LoggerInterface
     */
    private function logger()
    {
        return $this->make('logger');
    }
}
