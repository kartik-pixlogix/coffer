# File drop zone

Use the drop zone for receipts, attachments and CSV imports: a real file input covers the dashed area, so click, keyboard and drag all work.

- Markup: `<label class="cf-drop">` with an icon, the instruction, the limits and `<input type="file">`. `cf-drop--row` is the 48 px inline version for forms.
- States: hover darkens the dash; `.is-drag` turns solid on the `selected` tint while a file is over it; uploading becomes a `cf-file` row with `cf-progress-mini`; done shows an Uploaded pill and a remove button; `.is-error` names the file and the allowed types.
