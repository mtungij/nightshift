@component('layouts.nightshift')
@php($t = fn($text) => \App\Support\UiText::get($text))
@include('partials.workspace-mount')
<div class="workspace-admin-page" data-workspace-page="audit" data-workspace-group="Administration" hidden>
<div class="page-heading"><div><div class="eyebrow">{{ $t('Administration') }}</div><h1>{{ $t('Audit trail') }}</h1><p>{{ $t('A clear record of who did what, when, and why.') }}</p></div><div class="heading-actions"><a class="button" data-report-pdf href="{{ request()->fullUrlWithQuery(['pdf'=>1,'export'=>null,'page'=>null]) }}">{{ $t('Download PDF') }}</a><a class="button" href="{{ request()->fullUrlWithQuery(['export'=>1, 'page'=>null,'pdf'=>null]) }}">{{ $t('Export audit log') }}</a></div></div>
<form method="GET" class="panel panel-body admin-filters" data-report-shifts="Audit trail">
<label class="form-field">{{ $t('Search') }}<input name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ $t('Search action, user or reference…') }}"></label>
@foreach(['from'=>'From','to'=>'To'] as $key=>$label)<label class="form-field">{{ $t($label) }}<input type="date" name="{{ $key }}" value="{{ $filters[$key] ?? '' }}"></label>@endforeach
@foreach(['user_id'=>['User',$users], 'branch_id'=>['Branch',$branches], 'business_unit_id'=>['Business Unit',$units]] as $key=>[$label,$options])<label class="form-field">{{ $t($label) }}<select name="{{ $key }}"><option value="">{{ $t('All') }}</option>@foreach($options as $option)<option value="{{ $option->id }}" @selected(($filters[$key] ?? '') == $option->id)>{{ $option->name }}</option>@endforeach</select></label>@endforeach
@include('partials.report-shift-filter')
<label class="form-field">{{ $t('Module') }}<select name="module"><option value="">{{ $t('All') }}</option>@foreach($modules as $module)<option @selected(($filters['module'] ?? '') === $module)>{{ $module }}</option>@endforeach</select></label>
<label class="form-field">{{ $t('Action') }}<select name="action"><option value="">{{ $t('All') }}</option>@foreach($actions as $action)<option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $t(\App\Services\AuditTrailService::ACTIONS[$action] ?? ucwords(strtolower(str_replace('_',' ',$action)))) }}</option>@endforeach</select></label><button class="button primary">{{ $t('Filter') }}</button>
</form>
<section class="panel panel-body"><table class="data-table responsive-records audit-table"><thead><tr>@foreach(['Time','User','Action','Record','Summary','Details'] as $label)<th>{{ $t($label) }}</th>@endforeach</tr></thead><tbody>
@forelse($rows as $row)<tr>@foreach(['time'=>'Time','user'=>'User','action'=>'Action','reference'=>'Record','summary'=>'Summary'] as $key=>$label)<td data-label="{{ $t($label) }}">{{ $row[$key] }}</td>@endforeach<td data-label="{{ $t('Details') }}"><button type="button" class="button" data-audit-open="audit-{{ $row['id'] }}">{{ $t('View details') }}</button>
<dialog id="audit-{{ $row['id'] }}" class="audit-dialog"><div class="page-heading"><h2>{{ $row['action'] }}</h2><button type="button" class="button" data-audit-close>{{ $t('Close') }}</button></div>
<dl class="audit-meta">@foreach(['code'=>'Technical action','module'=>'Module','user'=>'User','time'=>'Timestamp','reference'=>'Reference'] as $key=>$label)<dt>{{ $t($label) }}</dt><dd>{{ $row[$key] }}</dd>@endforeach</dl>
<h3>{{ $t('Changes') }}</h3>@forelse($row['changes'] as $change)<div class="audit-change"><strong>{{ $change['field'] }}</strong><div><span>{{ $t('Previous value') }}: {{ $change['previous'] }}</span><span>→</span><span>{{ $t('New value') }}: {{ $change['next'] }}</span></div></div>@empty<p>{{ $t('No field changes') }}</p>@endforelse
<details><summary>{{ $t('Raw details (redacted)') }}</summary><h3>{{ $t('Previous value') }}</h3><pre>{{ json_encode($row['previous'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre><h3>{{ $t('New value') }}</h3><pre>{{ json_encode($row['next'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre></details>
</dialog></td></tr>@empty<tr><td colspan="6">{{ $t('No audit records found.') }}</td></tr>@endforelse
</tbody></table><nav class="report-navigation">@if($page->previousPageUrl())<a class="button" href="{{ $page->previousPageUrl() }}">{{ $t('Previous') }}</a>@endif @if($page->nextPageUrl())<a class="button" href="{{ $page->nextPageUrl() }}">{{ $t('Next') }}</a>@endif</nav></section>
</div>
@endcomponent
