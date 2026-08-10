<div data-document-state="uploading" role="status" aria-live="polite">
    <span aria-hidden="true" data-icon="file">[file]</span><span>Uploading {{ $filename ?? 'file' }}{{ $type ? " ({$type})" : '' }}{{ $size ? " {$size}" : '' }}{{ $progress !== null ? " ({$progress}%)" : '' }}</span>
    @if($progress !== null)<progress max="100" value="{{ $progress }}">{{ $progress }}%</progress>@endif
    @if($cancellable)<button type="button" data-action="cancel-upload">Cancel upload</button>@endif
</div>
