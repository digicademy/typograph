<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2026 Frodo Podschwadek <frodo.podschwadek@adwmainz.de>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

namespace Digicademy\TypoGraph\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * CLI command that sets up example tables, schema files, and site
 * configuration for TypoGraph API testing.
 *
 * Run this once before executing the API test suite to prepare the
 * database and site configuration. Flush the TYPO3 and PHP caches manually
 * via the application backend to avoid potential OPcache issues affecting
 * cached site.
 *
 * @see https://github.com/TYPO3-Console/TYPO3-Console/issues/983#issuecomment-824619309
 *
 * Usage:
 *   vendor/bin/typo3 typograph:example --site=main
 *   vendor/bin/typo3 typograph:example --site=main --no-seed
 *
 * @author Frodo Podschwadek <frodo.podschwadek@adwmainz.de>
 */
#[AsCommand(
    name: 'typograph:example',
    description: 'Sets up example tables, schema, and site config for TypoGraph API tests.',
)]
class ExampleCommand extends Command
{
    private const TABLE_TAXONOMIES = 'tx_typograph_example_taxonomies';
    private const TABLE_DISCIPLINES = 'tx_typograph_example_disciplines';
    private const TABLE_EXPERTS = 'tx_typograph_example_experts';
    private const TABLE_MM = 'tx_typograph_example_experts_disciplines_mm';

    /** @var list<array{name: string, description: string}> */
    private const TAXONOMIES = [
        ['name' => 'Natural Sciences', 'description' => 'Study of natural phenomena through observation and experimentation'],
        ['name' => 'Humanities', 'description' => 'Study of human culture, history, and expression'],
        ['name' => 'Social Sciences', 'description' => 'Study of human society and social relationships'],
        ['name' => 'Formal Sciences', 'description' => 'Study of abstract formal systems and structures'],
        ['name' => 'Applied Sciences', 'description' => 'Practical application of scientific knowledge'],
        ['name' => 'Engineering Sciences', 'description' => 'Design and building of systems and structures'],
        ['name' => 'Life Sciences', 'description' => 'Study of living organisms and life processes'],
        ['name' => 'Earth Sciences', 'description' => 'Study of the Earth and its geological processes'],
        ['name' => 'Health Sciences', 'description' => 'Study of health, disease, and healthcare systems'],
        ['name' => 'Information Sciences', 'description' => 'Study of information processing and computational systems'],
        ['name' => 'Environmental Sciences', 'description' => 'Study of the environment and ecological systems'],
        ['name' => 'Agricultural Sciences', 'description' => 'Study of farming, food production, and rural development'],
        ['name' => 'Arts and Design', 'description' => 'Study of creative expression, visual culture, and aesthetic design'],
        ['name' => 'Legal Sciences', 'description' => 'Study of law, governance, and justice systems'],
        ['name' => 'Interdisciplinary Studies', 'description' => 'Cross-domain research combining multiple academic disciplines'],
    ];

    /** @var list<array{name: string, description: string}> */
    private const DISCIPLINES = [
        ['name' => 'Physics', 'description' => 'Study of matter, energy, and fundamental forces of nature'],
        ['name' => 'Chemistry', 'description' => 'Study of substances, their properties, and transformations'],
        ['name' => 'Biology', 'description' => 'Study of living organisms and their vital processes'],
        ['name' => 'Mathematics', 'description' => 'Study of numbers, quantity, structure, and space'],
        ['name' => 'Computer Science', 'description' => 'Study of computation, algorithms, and information processing'],
        ['name' => 'History', 'description' => 'Study of past events, societies, and their significance'],
        ['name' => 'Philosophy', 'description' => 'Study of fundamental questions about existence, knowledge, and ethics'],
        ['name' => 'English Literature', 'description' => 'Study of literary works and criticism in the English language'],
        ['name' => 'Archaeology', 'description' => 'Study of human history through excavation of material remains'],
        ['name' => 'Sociology', 'description' => 'Study of social behavior, institutions, and societal structures'],
        ['name' => 'Psychology', 'description' => 'Study of mind, behavior, and mental processes'],
        ['name' => 'Economics', 'description' => 'Study of production, distribution, and consumption of resources'],
        ['name' => 'Mechanical Engineering', 'description' => 'Design and analysis of mechanical systems and machines'],
        ['name' => 'Medicine', 'description' => 'Study and practice of diagnosing, treating, and preventing disease'],
        ['name' => 'Geology', 'description' => 'Study of Earth solid materials, structures, and processes'],
    ];

    /** @var list<string> */
    private const FIRST_NAMES = [
        'Alice', 'Benjamin', 'Clara', 'David', 'Elena',
        'Fatima', 'George', 'Hannah', 'Ibrahim', 'Julia',
        'Kenji', 'Laura', 'Marcus', 'Nina', 'Oliver',
    ];

    /** @var list<string> */
    private const LAST_NAMES = [
        'Anderson', 'Bauer', 'Chen', 'Dubois', 'Evans',
        'Fischer', 'Garcia', 'Hoffmann', 'Ibrahim', 'Jensen',
        'Kim', 'Laurent', 'Martinez', 'Nakamura', 'Petrov',
    ];

    /** @var list<string> */
    private const BIOGRAPHY_TEMPLATES = [
        '%s %s is a leading researcher in the field of %s with numerous peer-reviewed publications.',
        'As a seasoned academic, %s %s has contributed significantly to advancements in %s.',
        '%s %s holds a distinguished professorship and specializes in %s.',
        'With over a decade of experience, %s %s conducts groundbreaking research in %s.',
        '%s %s is an internationally recognized expert whose work in %s has shaped the field.',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly CacheManager $cacheManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('site', 's', InputOption::VALUE_REQUIRED, 'Site identifier to configure', 'main')
            ->addOption('no-seed', null, InputOption::VALUE_NONE, 'Create tables but skip randomised data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $option = $input->getOption('site');
        $siteIdentifier = is_string($option) ? $option : 'main';
        $skipSeed = (bool)$input->getOption('no-seed');

        $this->recreateTables($output);

        if (!$skipSeed) {
            $this->seed($output);
        }

        $this->updateSiteConfig($siteIdentifier, $output);
        $this->flushSystemCache($output);

        $output->writeln('<info>Done. TypoGraph example data is ready.</info>');
        return Command::SUCCESS;
    }

    /**
     * Drop and recreate the four example tables.
     */
    private function recreateTables(OutputInterface $output): void
    {
        $connection = $this->connectionPool->getConnectionByName('Default');

        $connection->executeStatement('DROP TABLE IF EXISTS `' . self::TABLE_MM . '`');
        $connection->executeStatement('DROP TABLE IF EXISTS `' . self::TABLE_EXPERTS . '`');
        $connection->executeStatement('DROP TABLE IF EXISTS `' . self::TABLE_DISCIPLINES . '`');
        $connection->executeStatement('DROP TABLE IF EXISTS `' . self::TABLE_TAXONOMIES . '`');

        $connection->executeStatement('
            CREATE TABLE `' . self::TABLE_TAXONOMIES . '` (
                `uid` int(11) unsigned NOT NULL auto_increment,
                `pid` int(11) unsigned DEFAULT \'0\' NOT NULL,
                `tstamp` int(11) unsigned DEFAULT \'0\' NOT NULL,
                `crdate` int(11) unsigned DEFAULT \'0\' NOT NULL,
                `deleted` smallint(1) unsigned DEFAULT \'0\' NOT NULL,
                `hidden` smallint(1) unsigned DEFAULT \'0\' NOT NULL,
                `name` varchar(255) DEFAULT \'\' NOT NULL,
                `description` text,
                PRIMARY KEY (`uid`),
                KEY `parent` (`pid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        $connection->executeStatement('
            CREATE TABLE `' . self::TABLE_DISCIPLINES . '` (
                `uid` int(11) unsigned NOT NULL auto_increment,
                `pid` int(11) unsigned DEFAULT \'0\' NOT NULL,
                `tstamp` int(11) unsigned DEFAULT \'0\' NOT NULL,
                `crdate` int(11) unsigned DEFAULT \'0\' NOT NULL,
                `deleted` smallint(1) unsigned DEFAULT \'0\' NOT NULL,
                `hidden` smallint(1) unsigned DEFAULT \'0\' NOT NULL,
                `name` varchar(255) DEFAULT \'\' NOT NULL,
                `description` text,
                `taxonomy` int(11) unsigned DEFAULT \'0\' NOT NULL,
                PRIMARY KEY (`uid`),
                KEY `parent` (`pid`),
                KEY `taxonomy` (`taxonomy`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        $connection->executeStatement('
            CREATE TABLE `' . self::TABLE_EXPERTS . '` (
                `uid` int(11) unsigned NOT NULL auto_increment,
                `pid` int(11) unsigned DEFAULT \'0\' NOT NULL,
                `tstamp` int(11) unsigned DEFAULT \'0\' NOT NULL,
                `crdate` int(11) unsigned DEFAULT \'0\' NOT NULL,
                `deleted` smallint(1) unsigned DEFAULT \'0\' NOT NULL,
                `hidden` smallint(1) unsigned DEFAULT \'0\' NOT NULL,
                `first_name` varchar(255) DEFAULT \'\' NOT NULL,
                `last_name` varchar(255) DEFAULT \'\' NOT NULL,
                `email` varchar(255) DEFAULT \'\' NOT NULL,
                `biography` text,
                `primary_discipline` int(11) unsigned DEFAULT \'0\' NOT NULL,
                `disciplines` int(11) unsigned DEFAULT \'0\' NOT NULL,
                PRIMARY KEY (`uid`),
                KEY `parent` (`pid`),
                KEY `primary_discipline` (`primary_discipline`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        $connection->executeStatement('
            CREATE TABLE `' . self::TABLE_MM . '` (
                `uid_local` int(11) unsigned DEFAULT \'0\' NOT NULL,
                `uid_foreign` int(11) unsigned DEFAULT \'0\' NOT NULL,
                `sorting` int(11) unsigned DEFAULT \'0\' NOT NULL,
                `sorting_foreign` int(11) unsigned DEFAULT \'0\' NOT NULL,
                KEY `uid_local` (`uid_local`),
                KEY `uid_foreign` (`uid_foreign`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        $output->writeln('Example tables created.');
    }

    /**
     * Seed all example tables with randomised data.
     */
    private function seed(OutputInterface $output): void
    {
        $taxonomyUids = $this->seedTaxonomies($output);
        $disciplineData = $this->seedDisciplines($taxonomyUids, $output);
        $this->seedExperts($disciplineData, $output);
    }

    /**
     * Seed taxonomy records.
     *
     * @return list<int> UIDs of the inserted taxonomy records
     */
    private function seedTaxonomies(OutputInterface $output): array
    {
        $connection = $this->connectionPool->getConnectionByName('Default');
        $count = random_int(5, 15);
        $pool = self::TAXONOMIES;
        shuffle($pool);
        $selected = array_slice($pool, 0, $count);
        $now = time();
        $uids = [];

        foreach ($selected as $taxonomy) {
            $connection->insert(self::TABLE_TAXONOMIES, [
                'pid' => 0,
                'tstamp' => $now,
                'crdate' => $now,
                'name' => $taxonomy['name'],
                'description' => $taxonomy['description'],
            ]);
            $uids[] = (int)$connection->lastInsertId();
        }

        $output->writeln('Seeded ' . count($uids) . ' taxonomies.');
        return $uids;
    }

    /**
     * Seed discipline records, each assigned to a random taxonomy.
     *
     * @param list<int> $taxonomyUids
     * @return list<array{uid: int, name: string}> UID and name of each inserted discipline
     */
    private function seedDisciplines(array $taxonomyUids, OutputInterface $output): array
    {
        $connection = $this->connectionPool->getConnectionByName('Default');
        $count = random_int(5, 15);
        $pool = self::DISCIPLINES;
        shuffle($pool);
        $selected = array_slice($pool, 0, $count);
        $now = time();
        $data = [];

        foreach ($selected as $discipline) {
            $taxonomyUid = $taxonomyUids[array_rand($taxonomyUids)];
            $connection->insert(self::TABLE_DISCIPLINES, [
                'pid' => 0,
                'tstamp' => $now,
                'crdate' => $now,
                'name' => $discipline['name'],
                'description' => $discipline['description'],
                'taxonomy' => $taxonomyUid,
            ]);
            $data[] = [
                'uid' => (int)$connection->lastInsertId(),
                'name' => $discipline['name'],
            ];
        }

        $output->writeln('Seeded ' . count($data) . ' disciplines.');
        return $data;
    }

    /**
     * Seed expert records with primary discipline (uid relation) and
     * multiple disciplines via MM table (mmTable relation).
     *
     * @param list<array{uid: int, name: string}> $disciplineData
     */
    private function seedExperts(array $disciplineData, OutputInterface $output): void
    {
        $connection = $this->connectionPool->getConnectionByName('Default');
        $count = random_int(5, 15);
        $now = time();

        $firstNames = self::FIRST_NAMES;
        $lastNames = self::LAST_NAMES;
        shuffle($firstNames);
        shuffle($lastNames);

        $disciplineUids = array_column($disciplineData, 'uid');
        $disciplineNames = array_column($disciplineData, 'name', 'uid');

        $expertCount = 0;
        for ($i = 0; $i < $count; $i++) {
            $firstName = $firstNames[$i % count($firstNames)];
            $lastName = $lastNames[$i % count($lastNames)];
            $email = strtolower($firstName . '.' . $lastName) . '@example.org';

            $primaryUid = $disciplineUids[array_rand($disciplineUids)];
            $primaryName = $disciplineNames[$primaryUid];

            $template = self::BIOGRAPHY_TEMPLATES[array_rand(self::BIOGRAPHY_TEMPLATES)];
            $biography = sprintf($template, $firstName, $lastName, $primaryName);

            $maxMm = max(1, min(3, count($disciplineUids)));
            $mmCount = random_int(1, $maxMm);
            $shuffledUids = $disciplineUids;
            shuffle($shuffledUids);
            $mmUids = array_slice($shuffledUids, 0, $mmCount);

            if (!in_array($primaryUid, $mmUids, true)) {
                $mmUids[] = $primaryUid;
            }
            $mmTotal = count($mmUids);

            $connection->insert(self::TABLE_EXPERTS, [
                'pid' => 0,
                'tstamp' => $now,
                'crdate' => $now,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'biography' => $biography,
                'primary_discipline' => $primaryUid,
                'disciplines' => $mmTotal,
            ]);
            $expertUid = (int)$connection->lastInsertId();

            foreach ($mmUids as $sorting => $disciplineUid) {
                $connection->insert(self::TABLE_MM, [
                    'uid_local' => $expertUid,
                    'uid_foreign' => $disciplineUid,
                    'sorting' => $sorting + 1,
                    'sorting_foreign' => 0,
                ]);
            }

            $expertCount++;
        }

        $output->writeln('Seeded ' . $expertCount . ' experts with MM relations.');
    }

    /**
     * Write the typograph configuration block into the site config file,
     * replicating how SiteConfiguration::write() formats the YAML.
     */
    private function updateSiteConfig(string $siteIdentifier, OutputInterface $output): void
    {
        $configFile = Environment::getConfigPath() . '/sites/' . $siteIdentifier . '/config.yaml';

        if (!is_file($configFile)) {
            throw new \RuntimeException('Site config file not found: ' . $configFile);
        }

        $raw = Yaml::parseFile($configFile);
        $configuration = is_array($raw) ? $raw : [];
        $configuration['typograph'] = $this->getTypographConfiguration();
        ksort($configuration);
        file_put_contents($configFile, Yaml::dump($configuration, 99, 2));

        $output->writeln('Site config updated for site "' . $siteIdentifier . '".');
    }

    /**
     * Return the TypoGraph configuration array for the example data set.
     *
     * @return array<string, mixed>
     */
    private function getTypographConfiguration(): array
    {
        return [
            'schemaFiles' => [
                'EXT:typograph/Resources/Private/Schemas/Pagination.graphql',
                'EXT:typograph/Resources/Private/Schemas/ExampleQuery.graphql',
                'EXT:typograph/Resources/Private/Schemas/ExampleTypes.graphql',
            ],
            'tableMapping' => [
                'taxonomies' => 'tx_typograph_example_taxonomies',
                'taxonomy' => 'tx_typograph_example_taxonomies',
                'taxonomyConnection' => 'tx_typograph_example_taxonomies',
                'disciplines' => 'tx_typograph_example_disciplines',
                'discipline' => 'tx_typograph_example_disciplines',
                'disciplineConnection' => 'tx_typograph_example_disciplines',
                'experts' => 'tx_typograph_example_experts',
                'expert' => 'tx_typograph_example_experts',
                'expertConnection' => 'tx_typograph_example_experts',
            ],
            'relations' => [
                'Discipline' => [
                    'taxonomy' => [
                        'storageType' => 'uid',
                        'sourceField' => 'taxonomy',
                        'targetType' => 'taxonomy',
                    ],
                    'experts' => [
                        'storageType' => 'mmTable',
                        'targetType' => 'expert',
                        'mmTable' => 'tx_typograph_example_experts_disciplines_mm',
                        'mmSourceField' => 'uid_foreign',
                        'mmTargetField' => 'uid_local',
                        'mmSortingField' => 'sorting_foreign',
                    ],
                ],
                'Expert' => [
                    'primaryDiscipline' => [
                        'storageType' => 'uid',
                        'sourceField' => 'primary_discipline',
                        'targetType' => 'discipline',
                    ],
                    'disciplines' => [
                        'storageType' => 'mmTable',
                        'targetType' => 'discipline',
                        'mmTable' => 'tx_typograph_example_experts_disciplines_mm',
                    ],
                ],
                'Taxonomy' => [
                    'disciplines' => [
                        'storageType' => 'foreignKey',
                        'targetType' => 'discipline',
                        'foreignKeyField' => 'taxonomy',
                    ],
                ],
            ],
        ];
    }

    /**
     * Flush the system cache group so any cached site configuration is cleared.
     */
    private function flushSystemCache(OutputInterface $output): void
    {
        $this->cacheManager->flushCachesInGroup('system');
        $output->writeln('System cache flushed.');
        $output->writeln('HEADS UP! Depending on your particular system setup, it is recommended use the Maintenance / Flush TYPO3 and PHP Cache backend option to make sure the OPcache gets cleared completely.');
    }
}
