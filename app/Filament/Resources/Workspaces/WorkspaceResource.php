<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workspaces;

use App\Enums\PlanLimit;
use App\Filament\Resources\Workspaces\Pages\ListWorkspaces;
use App\Filament\Resources\Workspaces\Pages\ViewWorkspace;
use App\Models\Company;
use App\Models\Impersonation;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSuspension as WorkspaceSuspensionRecord;
use App\Services\Billing\PlanUsage;
use App\Services\Billing\WorkspaceBilling;
use App\Services\Platform\Impersonator;
use App\Services\Platform\PlatformMetrics;
use App\Services\Platform\WorkspaceSuspension;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Workspaces (spec §22, D86): list with plan, MRR, users, companies, status and created;
 * a read-only detail with usage and subscription; Suspend / Unsuspend and Impersonate
 * (reason required, audited). Nothing here writes to a tenant's books: there are no create,
 * edit or delete pages, and tenant-scoped models are only counted, with the company scope
 * removed explicitly (PlanUsage).
 */
final class WorkspaceResource extends Resource
{
    protected static ?string $model = Workspace::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    /** @return Builder<Workspace> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Workspace> $query */
        $query = parent::getEloquentQuery();

        return $query->with(['owner:id,name,email', 'plan'])
            ->withCount('companies')
            ->addSelect(['users_count' => Membership::query()
                ->selectRaw('count(distinct company_user.user_id)')
                ->join('companies', 'companies.id', '=', 'company_user.company_id')
                ->whereColumn('companies.workspace_id', 'workspaces.id')]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('owner.email')->label('Owner')->searchable()->toggleable(),
                TextColumn::make('plan.name')->label('Plan')->placeholder('None'),
                TextColumn::make('billing_status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Workspace $record): string => $record->isSuspended() ? 'suspended' : app(PlatformMetrics::class)->status($record)->status)
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'trial' => 'info',
                        'past_due', 'expired' => 'warning',
                        'suspended', 'canceled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('mrr')
                    ->label('MRR')
                    ->toggleable()
                    ->state(function (Workspace $record): string {
                        $mrr = app(PlatformMetrics::class)->mrrFor($record);

                        return $mrr === null ? '—' : PlatformMetrics::money($mrr['amount'], $mrr['currency']);
                    }),
                // A workspace with no company yet still has its owner (S7).
                TextColumn::make('users_count')->label('Users')->numeric()->sortable()->formatStateUsing(fn (mixed $state): int => max(1, (int) $state)),
                TextColumn::make('companies_count')->label('Companies')->numeric()->sortable()->toggleable(),
                IconColumn::make('suspended_at')->label('Suspended')->boolean()->state(fn (Workspace $record): bool => $record->isSuspended())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Created')->date('j M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('suspended_at')->label('Suspended')->nullable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Workspace')->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('owner.name')->label('Owner'),
                TextEntry::make('owner.email')->label('Owner email')->copyable(),
                TextEntry::make('created_at')->label('Created')->dateTime('j M Y, g:i a'),
                TextEntry::make('suspended_at')->label('Suspended')->dateTime('j M Y, g:i a')->placeholder('No'),
                TextEntry::make('suspended_reason')->label('Suspension reason')->placeholder('—')->columnSpan(2),
            ]),
            Section::make('Subscription')->columns(3)->schema([
                TextEntry::make('status')->badge()->state(fn (Workspace $record): string => app(PlatformMetrics::class)->status($record)->status),
                TextEntry::make('plan.name')->label('Plan')->placeholder('None (unmanaged)'),
                TextEntry::make('cycle')->state(fn (Workspace $record): string => app(PlatformMetrics::class)->status($record)->cycle.' · '.strtoupper(app(PlatformMetrics::class)->status($record)->currency)),
                TextEntry::make('mrr')->label('MRR')->state(function (Workspace $record): string {
                    $mrr = app(PlatformMetrics::class)->mrrFor($record);

                    return $mrr === null ? '—' : PlatformMetrics::money($mrr['amount'], $mrr['currency']);
                }),
                TextEntry::make('trial_ends_at')->label('Trial ends')->date('j M Y')->placeholder('—'),
                TextEntry::make('renews_at')->label('Renews')->date('j M Y')->placeholder('—'),
                TextEntry::make('past_due_since')->label('Past due since')->date('j M Y')->placeholder('—'),
                TextEntry::make('stripe_id')->label('Stripe customer')->placeholder('—')->copyable(),
            ]),
            Section::make('Usage')->columns(4)->schema(array_map(
                fn (PlanLimit $limit) => TextEntry::make('usage_'.$limit->value)
                    ->label(ucfirst(str_replace('_', ' ', $limit->value)))
                    ->state(function (Workspace $record) use ($limit): string {
                        $row = app(PlanUsage::class)->usage($record)[$limit->value];
                        $unit = $limit === PlanLimit::Storage ? ' MB' : '';

                        return $row['used'].$unit.' of '.($row['limit'] === null ? 'unlimited' : $row['limit'].$unit);
                    }),
                PlanLimit::cases(),
            )),
            Section::make('Companies and people')->columns(1)->schema([
                TextEntry::make('company_list')
                    ->label('Companies')
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->state(fn (Workspace $record): array => $record->companies()->orderBy('name')->get()
                        ->map(fn (Company $company): string => $company->name.' ('.($company->type->value === 'jv' ? 'JV' : 'Single').')')
                        ->all()),
                TextEntry::make('people')
                    ->label('People')
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->state(fn (Workspace $record): array => self::people($record)),
            ]),
            Section::make('Suspensions')->schema([
                TextEntry::make('suspensions')
                    ->hiddenLabel()
                    ->listWithLineBreaks()
                    ->placeholder('Never suspended')
                    ->state(fn (Workspace $record): array => WorkspaceSuspensionRecord::query()
                        ->with(['suspender:id,name', 'unsuspender:id,name'])
                        ->where('workspace_id', $record->id)
                        ->latest('suspended_at')
                        ->limit(20)
                        ->get()
                        ->map(fn (WorkspaceSuspensionRecord $s): string => $s->suspended_at->timezone('Asia/Kolkata')->format('j M Y, g:i a')
                            .' · '.($s->suspender->name ?? 'A platform admin').' · '.$s->reason
                            .($s->unsuspended_at === null ? ' (still suspended)' : ' · unsuspended '.$s->unsuspended_at->timezone('Asia/Kolkata')->format('j M Y, g:i a').' by '.($s->unsuspender->name ?? 'a platform admin')))
                        ->all()),
            ])->collapsible(),
            Section::make('Impersonations')->schema([
                TextEntry::make('impersonations')
                    ->hiddenLabel()
                    ->listWithLineBreaks()
                    ->placeholder('None yet')
                    ->state(fn (Workspace $record): array => Impersonation::query()
                        ->with(['admin:id,name', 'user:id,name'])
                        ->where('workspace_id', $record->id)
                        ->latest('started_at')
                        ->limit(20)
                        ->get()
                        ->map(fn (Impersonation $i): string => $i->started_at->timezone('Asia/Kolkata')->format('j M Y, g:i a').' · '.$i->admin->name.' as '.$i->user->name.' · '.$i->reason.($i->ended_at === null ? ' (open)' : ''))
                        ->all()),
            ])->collapsible(),
        ]);
    }

    /**
     * Everyone in the workspace's companies, each once: "Name <email> — Admin in Vertex Labs".
     *
     * @return list<string>
     */
    public static function people(Workspace $workspace): array
    {
        return array_values(User::query()
            ->whereHas('companies', fn (Builder $q) => $q->where('workspace_id', $workspace->id))
            ->with(['companies' => fn ($q) => $q->where('workspace_id', $workspace->id)])
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): string => $user->name.' <'.$user->email.'> — '.$user->companies
                ->map(fn (Company $c): string => $c->getRelation('membership')->role->label().' in '.$c->name)
                ->implode(', '))
            ->all());
    }

    /** @return array<int, string> user id => label, for the Impersonate picker */
    public static function userOptions(Workspace $workspace): array
    {
        return User::query()
            ->whereHas('companies', fn (Builder $q) => $q->where('workspace_id', $workspace->id))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->name.' <'.$user->email.'>'])
            ->all();
    }

    public static function admin(): PlatformAdmin
    {
        /** @var PlatformAdmin */
        return Auth::guard('platform')->user();
    }

    public static function suspendAction(): Action
    {
        return Action::make('suspend')
            ->label('Suspend')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Workspace $record): bool => ! $record->isSuspended())
            ->modalDescription('Everyone in this workspace sees a "suspended" page instead of their companies until you unsuspend it. Nothing is deleted.')
            ->schema([
                Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(1000),
            ])
            ->action(function (array $data, Workspace $record): void {
                app(WorkspaceSuspension::class)->suspend($record, self::admin(), (string) $data['reason']);
                Notification::make()->title('Workspace suspended')->success()->send();
            });
    }

    public static function unsuspendAction(): Action
    {
        return Action::make('unsuspend')
            ->label('Unsuspend')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Workspace $record): bool => $record->isSuspended())
            ->requiresConfirmation()
            ->action(function (Workspace $record): void {
                app(WorkspaceSuspension::class)->unsuspend($record, self::admin());
                Notification::make()->title('Workspace restored')->success()->send();
            });
    }

    public static function impersonateAction(): Action
    {
        return Action::make('impersonate')
            ->label('Impersonate')
            ->icon(Heroicon::OutlinedUserCircle)
            ->color('warning')
            ->modalDescription('You will use Coffer as this person, with a banner on every page. It is logged with your reason. Vault secrets stay locked.')
            ->schema(fn (Workspace $record): array => [
                Select::make('user_id')->label('Person')->options(self::userOptions($record))->required()->searchable(),
                Textarea::make('reason')->label('Reason')->helperText('Why you need to see their account, e.g. the support ticket.')->required()->minLength(10)->maxLength(1000),
            ])
            ->action(function (array $data, Workspace $record, Action $action): void {
                $user = User::query()->findOrFail((int) $data['user_id']);

                abort_unless(array_key_exists($user->id, self::userOptions($record)), 403);

                app(Impersonator::class)->start(self::admin(), $user, (string) $data['reason'], request(), $record);

                $action->redirect('/home');
            });
    }

    /** S7 (D118): put a workspace without a paid subscription on another plan's limits. Audited. */
    public static function changePlanAction(): Action
    {
        return Action::make('changePlan')
            ->label('Change plan')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (Workspace $record): bool => ! app(WorkspaceBilling::class)->for($record)->hasSubscription())
            ->modalDescription('For a workspace without a paid subscription (a trial, or one we bill by hand). Subscribed workspaces change plan in Billing or Stripe.')
            ->schema([
                Select::make('plan_id')->label('Plan')->options(fn (): array => Plan::query()->orderBy('position')->pluck('name', 'id')->all())->required(),
                Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500),
            ])
            ->action(function (array $data, Workspace $record): void {
                $from = $record->plan?->name;
                $record->forceFill(['plan_id' => (int) $data['plan_id']])->save();
                app(WorkspaceBilling::class)->forget($record);

                activity('platform')->causedBy(self::admin())->performedOn($record)
                    ->withProperties(['from' => $from, 'to' => $record->refresh()->plan?->name, 'reason' => (string) $data['reason']])
                    ->log('workspace plan changed');
                Notification::make()->title('Plan changed')->success()->send();
            });
    }

    /** S7 (D118): give a trial more days. Audited. */
    public static function extendTrialAction(): Action
    {
        return Action::make('extendTrial')
            ->label('Extend trial')
            ->icon(Heroicon::OutlinedClock)
            ->visible(fn (Workspace $record): bool => ! app(WorkspaceBilling::class)->for($record)->hasSubscription())
            ->schema([
                TextInput::make('days')->label('Extra days')->integer()->minValue(1)->maxValue(90)->required()->default(14),
                Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500),
            ])
            ->action(function (array $data, Workspace $record): void {
                $from = $record->trial_ends_at;
                $base = $from !== null && $from->isFuture() ? $from : now();
                $record->forceFill(['trial_ends_at' => $base->addDays((int) $data['days'])])->save();
                app(WorkspaceBilling::class)->forget($record);

                activity('platform')->causedBy(self::admin())->performedOn($record)
                    ->withProperties(['from' => $from?->toDateString(), 'to' => $record->trial_ends_at?->toDateString(), 'days' => (int) $data['days'], 'reason' => (string) $data['reason']])
                    ->log('workspace trial extended');
                Notification::make()->title('Trial extended')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkspaces::route('/'),
            'view' => ViewWorkspace::route('/{record}'),
        ];
    }
}
