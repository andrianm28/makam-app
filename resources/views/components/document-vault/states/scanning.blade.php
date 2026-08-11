<div data-document-state="scanning" role="status" aria-live="polite">
    <span aria-hidden="true" data-icon="lock">[lock]</span><span>{{ $filename ?? 'File' }}{{ $type ? " ({$type})" : '' }}{{ $size ? " {$size}" : '' }}</span><span>Scanning</span>
    <span>Security checks are pending. This link is valid for 300 seconds.</span>
    <span data-progress="indeterminate" class="sr-only">Pending</span>
</div>
