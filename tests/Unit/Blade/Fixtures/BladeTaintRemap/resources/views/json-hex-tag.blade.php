<script>
  window.x = @json(request()->input('q'), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
</script>
