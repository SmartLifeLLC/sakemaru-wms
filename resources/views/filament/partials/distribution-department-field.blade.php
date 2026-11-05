<fieldset class="distribution-department-field">
    <legend>担当部署 <span style="color:#dc2626;font-size:11px">必須</span></legend>
    <div class="department-options">
        @foreach(\App\Models\WmsDistributionBatch::RESPONSIBLE_DEPARTMENTS as $value => $label)
            <label class="department-choice">
                <input type="radio" name="{{ $departmentGroup }}" value="{{ $value }}" x-model="{{ $departmentModel }}" :disabled="{{ $departmentBusy }}" required>
                <span>{{ $label }}</span>
            </label>
        @endforeach
    </div>
    <style>
        .distribution-department-field{border:0;padding:0;margin:0;min-width:0;font-size:13px}
        .distribution-department-field legend{margin-bottom:6px}
        .distribution-department-field .department-options{display:flex;gap:10px}
        .distribution-department-field .department-options .department-choice{display:flex;align-items:center;gap:8px;flex:1;border:1px solid #cbd5e1;border-radius:6px;padding:9px 12px;cursor:pointer}
        .distribution-department-field .department-options .department-choice:has(input:checked){border-color:#2563eb;background:#eff6ff;color:#1d4ed8}
        .distribution-department-field .department-options input{width:16px;height:16px;padding:0;margin:0;flex:none;accent-color:#2563eb}
    </style>
</fieldset>
