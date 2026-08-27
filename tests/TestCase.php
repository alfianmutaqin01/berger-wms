<?php

namespace Tests;

use App\Support\CurrentActor;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * CurrentActor menyimpan aktor pada properti statis. Tanpa reset, aktor dari
     * satu test akan bocor ke test berikutnya dan membuat hasilnya bergantung
     * pada urutan eksekusi.
     */
    protected function tearDown(): void
    {
        CurrentActor::reset();

        parent::tearDown();
    }
}
