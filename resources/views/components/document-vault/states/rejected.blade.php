<div data-document-state="rejected" role="alert" data-tone="danger">
    <span aria-hidden="true" data-icon="warning">[warning]</span><span>{{ $filename ?? 'File' }}{{ $type ? " ({$type})" : '' }}{{ $size ? " {$size}" : '' }}</span><span>File rejected</span><span>{{ $reason }}</span>
    <button type="button" data-action="retry-upload">Try again</button>
</div>
