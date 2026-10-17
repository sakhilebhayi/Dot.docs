<?php

namespace App\Console\Commands;

use App\Models\Team;
use Illuminate\Console\Command;

class LinkTeamToInfoDot extends Command
{
    protected $signature = 'infodot:link-team {team : This app\'s team id} {uuid : The InfoDot team uuid}';

    protected $description = 'Link an existing team (and the files in it) to a company in InfoDot';

    public function handle(): int
    {
        $team = Team::find($this->argument('team'));
        $uuid = (string) $this->argument('uuid');

        if (! $team) {
            $this->error('No such team.');

            return self::FAILURE;
        }

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            $this->error('That is not a uuid.');

            return self::FAILURE;
        }

        if ($team->personal_team) {
            $this->error('Personal teams are not companies and cannot be linked.');

            return self::FAILURE;
        }

        if ($team->infodot_team_uuid && $team->infodot_team_uuid !== $uuid) {
            $this->error('This team is already linked to a different company.');

            return self::FAILURE;
        }

        if (Team::where('infodot_team_uuid', $uuid)->where('id', '!=', $team->id)->exists()) {
            $this->error('Another team here is already linked to that company.');

            return self::FAILURE;
        }

        $team->forceFill(['infodot_team_uuid' => $uuid])->save();
        $this->info("Team #{$team->id} \"{$team->name}\" is now linked to the InfoDot company {$uuid}.");

        return self::SUCCESS;
    }
}
