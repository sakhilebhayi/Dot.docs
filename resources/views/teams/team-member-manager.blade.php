<div class="stack">
    @if (Gate::check('addTeamMember', $team))
        <x-section-border />

        <x-form-section submit="addTeamMember">
            <x-slot name="title">
                {{ __('Add Team Member') }}
            </x-slot>

            <x-slot name="description">
                {{ __('Add a new team member to your team, allowing them to collaborate with you.') }}
            </x-slot>

            <x-slot name="form">
                <p class="field-hint">
                    {{ __('Please provide the email address of the person you would like to add to this team.') }}
                </p>

                <div class="field-row">
                    <x-label for="email" value="{{ __('Email') }}" />
                    <x-input id="email" type="email" wire:model="addTeamMemberForm.email" />
                    <x-input-error for="email" />
                </div>

                @if (count($this->roles) > 0)
                    <div class="field-row">
                        <span class="field-label" id="add-member-role">{{ __('Role') }}</span>
                        <x-input-error for="role" />

                        <ul class="ledger role-list" role="group" aria-labelledby="add-member-role">
                            @foreach ($this->roles as $role)
                                <li>
                                    <button type="button" class="ledger-row"
                                            aria-pressed="{{ $addTeamMemberForm['role'] === $role->key ? 'true' : 'false' }}"
                                            wire:click="$set('addTeamMemberForm.role', '{{ $role->key }}')">
                                        <x-shell.lamp :tone="$addTeamMemberForm['role'] === $role->key ? 'good' : 'idle'"
                                                      :word="$addTeamMemberForm['role'] === $role->key ? __('Chosen') : __('Not chosen')" />
                                        <span class="ledger-key">
                                            {{ $role->name }}
                                            <span class="ledger-sub">{{ $role->description }}</span>
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </x-slot>

            <x-slot name="actions">
                <x-action-message on="saved">
                    {{ __('Added.') }}
                </x-action-message>

                <x-button>
                    {{ __('Add') }}
                </x-button>
            </x-slot>
        </x-form-section>
    @endif

    @if ($team->teamInvitations->isNotEmpty() && Gate::check('addTeamMember', $team))
        <x-section-border />

        <x-action-section>
            <x-slot name="title">
                {{ __('Pending Team Invitations') }}
            </x-slot>

            <x-slot name="description">
                {{ __('These people have been invited to your team and have been sent an invitation email. They may join the team by accepting the email invitation.') }}
            </x-slot>

            <x-slot name="content">
                <ul class="ledger">
                    @foreach ($team->teamInvitations as $invitation)
                        <li class="ledger-row">
                            <span class="ledger-key">{{ $invitation->email }}</span>
                            <x-shell.lamp tone="signal" word="{{ __('Invited') }}" />
                            @if (Gate::check('removeTeamMember', $team))
                                <button type="button" class="btn btn-sm"
                                        wire:click="cancelTeamInvitation({{ $invitation->id }})">
                                    {{ __('Cancel') }}
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-slot>
        </x-action-section>
    @endif

    @if ($team->users->isNotEmpty())
        <x-section-border />

        <x-action-section>
            <x-slot name="title">
                {{ __('Team Members') }}
            </x-slot>

            <x-slot name="description">
                {{ __('All of the people that are part of this team.') }}
            </x-slot>

            <x-slot name="content">
                <ul class="ledger">
                    @foreach ($team->users->sortBy('name') as $user)
                        <li class="ledger-row">
                            <span class="face-plate face-plate-sm">
                                <img src="{{ $user->profile_photo_url }}" alt="{{ $user->name }}">
                            </span>
                            <span class="ledger-key">{{ $user->name }}</span>

                            @if (Gate::check('updateTeamMember', $team) && Laravel\Jetstream\Jetstream::hasRoles())
                                <button type="button" class="btn btn-sm" wire:click="manageRole('{{ $user->id }}')">
                                    {{ Laravel\Jetstream\Jetstream::findRole($user->membership->role)->name }}
                                </button>
                            @elseif (Laravel\Jetstream\Jetstream::hasRoles())
                                <span class="ledger-val">{{ Laravel\Jetstream\Jetstream::findRole($user->membership->role)->name }}</span>
                            @endif

                            @if ($this->user->id === $user->id)
                                <button type="button" class="btn btn-sm btn-danger" wire:click="$toggle('confirmingLeavingTeam')">
                                    {{ __('Leave') }}
                                </button>
                            @elseif (Gate::check('removeTeamMember', $team))
                                <button type="button" class="btn btn-sm btn-danger" wire:click="confirmTeamMemberRemoval('{{ $user->id }}')">
                                    {{ __('Remove') }}
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-slot>
        </x-action-section>
    @endif

    <x-dialog-modal wire:model.live="currentlyManagingRole">
        <x-slot name="title">
            {{ __('Manage Role') }}
        </x-slot>

        <x-slot name="content">
            <ul class="ledger role-list" role="group" aria-label="{{ __('Role') }}">
                @foreach ($this->roles as $role)
                    <li>
                        <button type="button" class="ledger-row"
                                aria-pressed="{{ $currentRole === $role->key ? 'true' : 'false' }}"
                                wire:click="$set('currentRole', '{{ $role->key }}')">
                            <x-shell.lamp :tone="$currentRole === $role->key ? 'good' : 'idle'"
                                          :word="$currentRole === $role->key ? __('Chosen') : __('Not chosen')" />
                            <span class="ledger-key">
                                {{ $role->name }}
                                <span class="ledger-sub">{{ $role->description }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="stopManagingRole" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-button wire:click="updateRole" wire:loading.attr="disabled">
                {{ __('Save') }}
            </x-button>
        </x-slot>
    </x-dialog-modal>

    <x-confirmation-modal wire:model.live="confirmingLeavingTeam">
        <x-slot name="title">
            {{ __('Leave Team') }}
        </x-slot>

        <x-slot name="content">
            {{ __('Are you sure you would like to leave this team?') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingLeavingTeam')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button wire:click="leaveTeam" wire:loading.attr="disabled">
                {{ __('Leave') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>

    <x-confirmation-modal wire:model.live="confirmingTeamMemberRemoval">
        <x-slot name="title">
            {{ __('Remove Team Member') }}
        </x-slot>

        <x-slot name="content">
            {{ __('Are you sure you would like to remove this person from the team?') }}
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$toggle('confirmingTeamMemberRemoval')" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button wire:click="removeTeamMember" wire:loading.attr="disabled">
                {{ __('Remove') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>
</div>
