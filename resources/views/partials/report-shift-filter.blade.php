@isset($shiftUnits)
<label class="form-field"><span>{{ $t('Business Unit') }}</span><select name="business_unit_id"><option value="">{{ $t('All Business Units') }}</option>@foreach($shiftUnits as $option)<option value="{{ $option->id }}" @selected(($filters['business_unit_id'] ?? '') == $option->id)>{{ $option->name }}</option>@endforeach</select></label>
@endisset
@isset($shiftCounters)
<label class="form-field"><span>{{ $t('Counter') }}</span><select name="stock_location_id"><option value="">{{ $t('All counters') }}</option>@foreach($shiftCounters as $option)<option value="{{ $option->id }}" @selected(($filters['stock_location_id'] ?? '') == $option->id)>{{ $option->name }}</option>@endforeach</select></label>
@endisset
<script type="application/json" data-report-shift-options>{!! json_encode(['units' => $shiftUnits ?? $units ?? [], 'counters' => $shiftCounters ?? []], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
<label class="form-field"><span>{{ $t('Shift') }}</span><select name="shift_id"><option value="">{{ $t('All Shifts') }}</option>@if(!empty($filters['shift_id']))<option value="{{ $filters['shift_id'] }}" selected>{{ $filters['shift_id'] }}</option>@endif</select></label>
<label class="form-field"><span>{{ $t('Find shift') }}</span><input type="search" data-shift-search placeholder="{{ $t('Shift or employee') }}"><button class="button small" type="button" data-shift-more hidden>{{ $t('Earlier shifts') }}</button></label>
<div class="report-shift-summary" data-shift-summary role="status"></div>
