{{-- The heading of a settings panel: a quiet micro-heading and one line saying
     what the panel is for. No gutter column, no card. --}}
<div>
    <h2 class="h-panel">{{ $title }}</h2>
    <p class="page-lede">{{ $description }}</p>
</div>
{{ $aside ?? '' }}
