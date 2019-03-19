<?php

namespace App\Console\Commands;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;

class ScanWebsite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'svs:version {--website=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Well-defined scanner to get a websites CMS version.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle(): void
    {
        $this->info('=== Scanning the website. This could take a few seconds. ===');

        $hasCandidates = $this->fileExists('candidates.json', 'local');

        if (!$hasCandidates)
        {
            $this->info('=== Scan interrupted. No candidates found. ===');

            return;
        }

        $candidatesFile = $this->retrieveFile('candidates.json');

        if (!is_string($candidatesFile) || $candidatesFile === '')
        {
            $this->info('=== Scan interrupted. Retrieving candidates failed. ===');

            return;
        }

        $candidates = json_decode($candidatesFile, true);

        if (!is_array($candidates) || !count($candidates))
        {
            $this->info('=== Scan interrupted. Could not read candidates. ===');

            return;
        }

        $httpClient = new Client;

        $website = $this->option('website');

        $detectedCms = $this->detectCms($candidates, $httpClient, $website);

        $version = $this->scan($candidates, $detectedCms, $httpClient, $website);
    }

    public function scan($candidates, $cms, $httpClient, $website)
    {
        $possibleVersions = [];

        foreach($candidates[$cms]['identifier'] as $filename => $hashInfo) {
            $sourceHashes = $hashInfo['data'];
            $readableUrl = preg_replace('/^\//', '', $filename);

            try {
                $response = $httpClient->get($website . $readableUrl);

                $body = (string) $response->getBody();

                $targetHash = md5($body);
            } catch (RequestException $e) {
                $this->info('=== Scan interrupted. File not found. ===');

                continue;
            }

            if (!isset($sourceHashes[$targetHash]))
            {
                continue;
            }

            $amountOfVersions = count($sourceHashes[$targetHash]);

            // If there is only one version, no further searching required
            if ($amountOfVersions === 1)
            {
                return $sourceHashes[$targetHash][0];
            }

            // Unable to detect which version because this file occurrences in more than one version
            if (!count($possibleVersions))
            {
                $possibleVersions = $sourceHashes[$targetHash];

                continue;
            }

            $possibleVersions = array_intersect($possibleVersions, $sourceHashes[$targetHash]);

            if (count($possibleVersions) === 1)
            {
                return $possibleVersions[0];
            }
        }

        if (count($possibleVersions) > 1)
        {
            foreach ($candidates[$cms]['versionproof'] as $versionNumber => $fileInfo) {
                if (in_array($versionNumber, $possibleVersions, true))
                {
                    // Continue here.
                }
            }
        }
    }

    private function fileExists(string $filename, string $disk): bool
    {
        return Storage::disk($disk)->exists($filename);
    }

    private function retrieveFile(string $filename): string
    {
        return Storage::get($filename);
    }

    public function limitCandidates($candidates, $limit): array
    {
        return array_splice($candidates["identifier"], 0, $limit);
    }

    public function detectCms(array $candidates, $httpClient, $website): string
    {
        $highestCandidates = [];

        foreach($candidates as $cms => $cmsData) {
            $bestCandidates = $this->limitCandidates($cmsData, 15);

            foreach($bestCandidates as $filename => $hashInfo) {
                // Remove first appearance of the slash
                $readableUrl = preg_replace('/^\//', '', $filename);

                //sleep(1);

                try {
                    $this->info('=== Scanning for CMS: '. $cms .' ===');

                    $httpClient->get($website . $readableUrl);

                    // Algorithm to find out which CMS is used by the users website

                    if(!isset($highestCandidates[$cms]))
                    {
                        $highestCandidates[$cms] = 0;
                    }

                    $highestCandidates[$cms]++;

                } catch (RequestException $e) {
                    $this->info('=== File not found: '. $readableUrl .' ===');

                    continue;
                }
            }
        }

        asort($highestCandidates);

        return array_key_last($highestCandidates);
    }
}
