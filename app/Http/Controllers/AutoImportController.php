<?php
/*
 * AutoImportController.php
 * Copyright (c) 2021 james@firefly-iii.org
 *
 * This file is part of the Firefly III Data Importer
 * (https://github.com/firefly-iii/data-importer).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);


namespace App\Http\Controllers;


use App\Console\AutoImports;
use App\Console\HaveAccess;
use App\Console\VerifyJSON;
use App\Exceptions\ImporterErrorException;
use Illuminate\Http\Request;
use Log;

/**
 *
 */
class AutoImportController extends Controller
{
    use HaveAccess, AutoImports, VerifyJSON;

    private string $directory;

    /**
     *
     */
    public function index(Request $request)
    {
        die('todo' . __METHOD__);
        $access = $this->haveAccess();
        if (false === $access) {
            throw new ImporterErrorException('Could not connect to your local Firefly III instance.');
        }

        $argument        = (string) ($request->get('directory') ?? './');
        $this->directory = realpath($argument);
        $this->line(sprintf('Going to automatically import everything found in %s (%s)', $this->directory, $argument));

        $files = $this->getFiles();
        if (0 === count($files)) {
            $this->line(sprintf('There are no files in directory %s', $this->directory));
            $this->line('To learn more about this process, read the docs:');
            $this->line('https://docs.firefly-iii.org/data-importer/');

            return ' ';
        }
        $this->line(sprintf('Found %d CSV + JSON file sets in %s', count($files), $this->directory));
        try {
            $this->importFiles($files);
        } catch (ImporterErrorException $e) {
            Log::error($e->getMessage());
            $this->line(sprintf('Import exception (see the logs): %s', $e->getMessage()));
        }

        return ' ';
    }

    public function line(string $string)
    {
        echo sprintf("%s: %s\n", date('Y-m-d H:i:s'), $string);
    }

    /**
     * @inheritDoc
     */
    public function error($string, $verbosity = null)
    {
        $this->line($string);
    }

    /**
     * @param      $string
     * @param null $verbosity
     */
    public function warn($string, $verbosity = null)
    {
        $this->line($string);
    }

    /**
     * @param      $string
     * @param null $verbosity
     */
    public function info($string, $verbosity = null)
    {
        $this->line($string);
    }
}
