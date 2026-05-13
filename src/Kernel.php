<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private function getRuntimeBaseDir(): string
    {
        return $this->getProjectDir() . DIRECTORY_SEPARATOR . '.runtime';
    }

    public function getCacheDir(): string
    {
        return $this->getRuntimeBaseDir() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . $this->environment;
    }

    public function getBuildDir(): string
    {
        return $this->getRuntimeBaseDir() . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getRuntimeBaseDir() . DIRECTORY_SEPARATOR . 'log';
    }
}
