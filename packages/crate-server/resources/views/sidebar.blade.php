{{-- Crate's own navigation, filled into the sidebar of every shell page that brings none of its own. --}}
<nav aria-label="Crate"
     class="bfc-sidebar-links"
     data-testid="crate-navigation">
    <a href="{{ route('crate.connect') }}"
       class="bfc-shell-link"
       @if ($connectActive) aria-current="page" @endif
       data-testid="crate-connect-navigation">
        Connect this machine
    </a>
</nav>
