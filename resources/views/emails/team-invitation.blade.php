@component('mail::message')
{{-- The team name is whatever its owner typed. Mail is rendered as Markdown, so it goes through MailText::plain()
     (every non-alphanumeric character as an entity) and is printed raw: {{ }} would escape the entities a second time,
     and plain {{ }} alone leaves `[text](url)` a live link (EA-1 / E-01). --}}
{!! __('You have been invited to join the :team team!', ['team' => \App\Support\MailText::plain($invitation->team->name, 80)]) !!}

@if (\App\Support\CentralSignIn::active())
{{ __('Sign in to Dot.docs with your InfoDot account, or create one there, using this email address. You come straight back to this invitation. You may accept it by clicking the button below:') }}

@elseif (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::registration()))
{{ __('If you do not have an account, you may create one by clicking the button below. After creating an account, you may click the invitation acceptance button in this email to accept the team invitation:') }}

@component('mail::button', ['url' => route('register')])
{{ __('Create Account') }}
@endcomponent

{{ __('If you already have an account, you may accept this invitation by clicking the button below:') }}

@else
{{ __('You may accept this invitation by clicking the button below:') }}
@endif


@component('mail::button', ['url' => $acceptUrl])
{{ __('Accept Invitation') }}
@endcomponent

{{ __('If you did not expect to receive an invitation to this team, you may discard this email.') }}
@endcomponent
