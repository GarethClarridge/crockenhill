<?php

namespace App\Filament\Resources;

use App\Enums\MeetingFrequency;
use App\Enums\MeetingType;
use App\Filament\Resources\MeetingResource\Pages;
use App\Models\Meeting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class MeetingResource extends Resource
{
    protected static ?string $model = Meeting::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'slug';

    /**
     * Resolve records by slug (the model's route key).
     */
    public static function resolveRecordRouteBinding(int|string $key): ?\Illuminate\Database\Eloquent\Model
    {
        return static::getModel()::query()->where('slug', $key)->first();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Meeting Details')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->helperText('URL-friendly identifier (e.g., coffee-cup)'),

                    Forms\Components\Select::make('type')
                        ->options(collect(MeetingType::cases())->mapWithKeys(
                            fn (MeetingType $type) => [$type->value => $type->label()]
                        ))
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('day')
                        ->placeholder('e.g., Sundays, First Tuesday of month')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('location')
                        ->placeholder('e.g., Church Hall')
                        ->maxLength(255),

                    Forms\Components\TimePicker::make('StartTime')
                        ->label('Start Time')
                        ->seconds(false),

                    Forms\Components\TimePicker::make('EndTime')
                        ->label('End Time')
                        ->seconds(false),

                    Forms\Components\TextInput::make('who')
                        ->label('Who is it for?')
                        ->placeholder('e.g., Everyone, Children aged 5-11')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Recurring Schedule')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('is_recurring')
                        ->label('Is this a recurring meeting?')
                        ->live(),

                    Forms\Components\Select::make('frequency')
                        ->options(collect(MeetingFrequency::cases())->mapWithKeys(
                            fn (MeetingFrequency $freq) => [$freq->value => $freq->label()]
                        ))
                        ->native(false)
                        ->visible(fn (Forms\Get $get) => $get('is_recurring')),

                    Forms\Components\DateTimePicker::make('meeting_date')
                        ->label('Meeting Date')
                        ->helperText(fn (Forms\Get $get) => $get('is_recurring')
                            ? 'First occurrence date for recurring meetings'
                            : 'Specific date for one-time meetings'
                        ),
                ]),

            Forms\Components\Section::make('Contact Information')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('LeadersPhone')
                        ->label("Leader's Phone")
                        ->tel()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('LeadersEmail')
                        ->label("Leader's Email")
                        ->email()
                        ->maxLength(255),
                ]),

            Forms\Components\Section::make('Content Page')
                ->description('Link to a Page for heading, description, body content, and images')
                ->schema([
                    Forms\Components\Select::make('page_id')
                        ->label('Content Page')
                        ->relationship('page', 'heading')
                        ->searchable()
                        ->preload()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('heading')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::slug($state))
                                ),
                            Forms\Components\TextInput::make('slug')
                                ->required()
                                ->maxLength(255),
                            Forms\Components\Hidden::make('area')
                                ->default('community'),
                            Forms\Components\Hidden::make('navigation')
                                ->default(false),
                            Forms\Components\Hidden::make('body')
                                ->default(''),
                            Forms\Components\Textarea::make('description')
                                ->label('SEO Description')
                                ->maxLength(155),
                            Forms\Components\MarkdownEditor::make('markdown')
                                ->label('Content'),
                        ])
                        ->editOptionForm([
                            Forms\Components\TextInput::make('heading')
                                ->required(),
                            Forms\Components\Textarea::make('description')
                                ->maxLength(155),
                            Forms\Components\MarkdownEditor::make('markdown'),
                        ])
                        ->helperText("Select an existing page or create a new one for this meeting's content."),
                ]),

            Forms\Components\Section::make('Options')
                ->schema([
                    Forms\Components\Toggle::make('pictures')
                        ->label('Has photo gallery')
                        ->helperText('Enable if this meeting has photos to display'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('heading')
                    ->label('Heading')
                    ->searchable(query: function ($query, string $search) {
                        return $query->whereHas('page', function ($q) use ($search) {
                            $q->where('heading', 'like', "%{$search}%");
                        })->orWhere('slug', 'like', "%{$search}%");
                    })
                    ->sortable(query: function ($query, string $direction) {
                        return $query->leftJoin('pages', 'meetings.page_id', '=', 'pages.id')
                            ->orderBy('pages.heading', $direction)
                            ->select('meetings.*');
                    }),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (MeetingType $state) => $state->label())
                    ->color(fn (MeetingType $state): string => match ($state) {
                        MeetingType::SUNDAY_AND_BIBLE_STUDIES => 'success',
                        MeetingType::CHILDREN_AND_YOUNG_PEOPLE => 'warning',
                        MeetingType::ADULTS => 'info',
                        MeetingType::OCCASIONAL => 'gray',
                    }),

                Tables\Columns\TextColumn::make('day')
                    ->searchable(),

                Tables\Columns\TextColumn::make('StartTime')
                    ->label('Time')
                    ->formatStateUsing(fn ($state, Meeting $record) => $state
                        ? $state->format('g:ia').($record->EndTime ? ' - '.$record->EndTime->format('g:ia') : '')
                        : '-'
                    ),

                Tables\Columns\IconColumn::make('is_recurring')
                    ->boolean()
                    ->label('Recurring'),

                Tables\Columns\TextColumn::make('calendarEvents_count')
                    ->counts('calendarEvents')
                    ->label('Events'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(collect(MeetingType::cases())->mapWithKeys(
                        fn (MeetingType $type) => [$type->value => $type->label()]
                    )),

                Tables\Filters\TernaryFilter::make('is_recurring')
                    ->label('Recurring'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Meeting $record) => "/community/{$record->slug}")
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMeetings::route('/'),
            'create' => Pages\CreateMeeting::route('/create'),
            'edit' => Pages\EditMeeting::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['slug', 'who', 'day'];
    }

    public static function getGlobalSearchResultDetails(\Illuminate\Database\Eloquent\Model $record): array
    {
        /** @var Meeting $record */
        return [
            'Type' => $record->type->label(),
        ];
    }
}
