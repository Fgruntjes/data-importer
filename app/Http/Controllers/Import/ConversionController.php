<?php
/*
 * ConversionController.php
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

namespace App\Http\Controllers\Import;


use App\Exceptions\ImporterErrorException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ConversionControllerMiddleware;
use App\Services\CSV\Conversion\RoutineManager as CSVRoutineManager;
use App\Services\Nordigen\Conversion\RoutineManager as NordigenRoutineManager;
use App\Services\Session\Constants;
use App\Services\Shared\Conversion\ConversionStatus;
use App\Services\Shared\Conversion\RoutineManagerInterface;
use App\Services\Shared\Conversion\RoutineStatusManager;
use App\Services\Spectre\Conversion\RoutineManager as SpectreRoutineManager;
use App\Support\Http\RestoresConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Log;
use Storage;

/**
 * Class ConversionController
 */
class ConversionController extends Controller
{
    use RestoresConfiguration;

    protected const DISK_NAME = 'jobs'; // TODO stored in several places

    /**
     * StartController constructor.
     */
    public function __construct()
    {
        parent::__construct();
        app('view')->share('pageTitle', 'Importing data...');
        $this->middleware(ConversionControllerMiddleware::class);
    }

    /**
     *
     */
    public function index()
    {
        Log::debug(sprintf('Now in %s', __METHOD__));
        $mainTitle = 'Convert the data';

        // create configuration:
        $configuration = $this->restoreConfiguration();


        Log::debug('Will now verify configuration content.');
        $jobBackUrl = route('back.mapping');
        if (empty($configuration->getDoMapping())) {
            // no mapping, back to roles
            Log::debug('NO role info in config, will send you back to roles..');
            $jobBackUrl = route('back.roles');
        }
        if (empty($configuration->getMapping())) {
            // back to mapping
            Log::debug('NO mapping in file, will send you back to mapping..');
            $jobBackUrl = route('back.mapping');
        }
        if (true === $configuration->isMapAllData()) {
            $jobBackUrl = route('back.mapping');
        }

        // job ID may be in session:
        $identifier = session()->get(Constants::CONVERSION_JOB_IDENTIFIER);
        $flow       = $configuration->getFlow();
        $nextUrl    = route('008-submit.index');

        // switch based on flow:
        if (!in_array($flow, config('importer.flows'), true)) {
            throw new ImporterErrorException(sprintf('Not a supported flow: "%s"', $flow));
        }
        /** @var RoutineManagerInterface $routine */
        if ('csv' === $flow) {
            $routine = new CSVRoutineManager($identifier);
        }
        if ('nordigen' === $flow) {
            $routine = new NordigenRoutineManager($identifier);
        }
        if ('spectre' === $flow) {
            $routine = new SpectreRoutineManager($identifier);
        }
        if ($configuration->isMapAllData() && in_array($flow, ['spectre', 'nordigen'], true)) {
            $nextUrl = route('006-mapping.index');
        }

        // may be a new identifier! Yay!
        $identifier = $routine->getIdentifier();

        Log::debug(sprintf('Conversion routine manager identifier is "%s"', $identifier));

        // store identifier in session so the status can get it.
        session()->put(Constants::CONVERSION_JOB_IDENTIFIER, $identifier);
        Log::debug(sprintf('Stored "%s" under "%s"', $identifier, Constants::CONVERSION_JOB_IDENTIFIER));

        return view('import.007-convert.index', compact('mainTitle', 'identifier', 'jobBackUrl', 'flow', 'nextUrl'));
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     * @throws ImporterErrorException
     */
    public function start(Request $request): JsonResponse
    {
        Log::debug(sprintf('Now at %s', __METHOD__));
        $identifier    = $request->get('identifier');
        $configuration = $this->restoreConfiguration();

        // now create the right class:
        $flow = $configuration->getFlow();
        if (!in_array($flow, config('importer.flows'), true)) {
            throw new ImporterErrorException(sprintf('Not a supported flow: "%s"', $flow));
        }
        /** @var RoutineManagerInterface $routine */
        if ('csv' === $flow) {
            $routine = new CSVRoutineManager($identifier);
        }
        if ('nordigen' === $flow) {
            $routine = new NordigenRoutineManager($identifier);
        }
        if ('spectre' === $flow) {
            $routine = new SpectreRoutineManager($identifier);
        }

        $importJobStatus = RoutineStatusManager::startOrFindConversion($identifier);

        RoutineStatusManager::setConversionStatus(ConversionStatus::CONVERSION_RUNNING);

        // then push stuff into the routine:
        $routine->setConfiguration($configuration);
        $result       = false;
        $transactions = [];
        try {
            $transactions = $routine->start();
            $result       = true;
        } catch (ImporterErrorException $e) {
            Log::error($e->getMessage());
            RoutineStatusManager::setConversionStatus(ConversionStatus::CONVERSION_ERRORED);
            return response()->json($importJobStatus->toArray());
        }
        if (0 === count($transactions)) {
            Log::error('Zero transactions!');
            RoutineStatusManager::setConversionStatus(ConversionStatus::CONVERSION_ERRORED);
            return response()->json($importJobStatus->toArray());

        }
        // save transactions in 'jobs' directory under the same key as the conversion thing.
        $disk = Storage::disk(self::DISK_NAME);
        try {
            $disk->put(sprintf('%s.json', $identifier), json_encode($transactions, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            Log::error(sprintf('JSON exception: %s', $e->getMessage()));
            RoutineStatusManager::setConversionStatus(ConversionStatus::CONVERSION_ERRORED);
            return response()->json($importJobStatus->toArray());
        }

        if (true === $result) {
            // set done:
            RoutineStatusManager::setConversionStatus(ConversionStatus::CONVERSION_DONE);

            // set config as complete.
            session()->put(Constants::CONVERSION_COMPLETE_INDICATOR, true);
        }


        return response()->json($importJobStatus->toArray());
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function status(Request $request): JsonResponse
    {

        $identifier = $request->get('identifier');
        Log::debug(sprintf('Now at %s(%s)', __METHOD__, $identifier));
        if (null === $identifier) {
            Log::warning('Identifier is NULL.');
            // no status is known yet because no identifier is in the session.
            // As a fallback, return empty status
            $fakeStatus = new ConversionStatus;

            return response()->json($fakeStatus->toArray());
        }
        $importJobStatus = RoutineStatusManager::startOrFindConversion($identifier);

        return response()->json($importJobStatus->toArray());
    }
}
