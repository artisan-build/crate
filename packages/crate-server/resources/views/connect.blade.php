<x-bfc-layout title="Connect this machine">
    @push('head')
        <style>
            .crate-connect { min-width: 0; overflow-wrap: anywhere; }
            .crate-connect pre { white-space: pre-wrap; word-break: break-all; }
        </style>
    @endpush

    <section class="crate-connect bfc-dashboard"
             data-testid="crate-connect">
        <p class="bfc-kicker">
            Composer
        </p>
        <h1 class="bfc-dashboard-title">
            Connect this machine
        </h1>
        <p class="bfc-dashboard-lede">
            Run one command in your terminal and Composer on this machine can install private packages from {{ $host }}. It saves a personal credential to your global auth.json, so nothing lands in a project you might commit.
        </p>

        @if ($command)
            <div class="bfc-panel"
                 data-testid="crate-connect-command">
                <p>
                    Copy this into your terminal. It works once, within the next {{ $minutes }} minutes.
                </p>
                <pre class="bfc-prompt-text"><code>{{ $command }}</code></pre>
                <p>
                    Want to read the script first? Open the address in the command in your browser. Looking at it does not use up the code.
                </p>
            </div>
        @else
            <form method="POST"
                  action="{{ route('crate.connect.store') }}"
                  class="bfc-actions">
                @csrf
                <button type="submit"
                        data-testid="crate-connect-issue"
                        class="bfc-button">
                    Get my command
                </button>
            </form>
        @endif
    </section>
</x-bfc-layout>
