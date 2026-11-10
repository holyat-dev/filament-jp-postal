<?php

declare(strict_types=1);

namespace Holyat\FilamentJpPostal\Tests\Fixtures;

use Holyat\FilamentJpPostal\Contracts\ShouldCacheLookups;

class CachingFakeDriver extends FakeDriver implements ShouldCacheLookups {}
