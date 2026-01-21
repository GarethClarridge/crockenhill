<?php

namespace App\Filament\Resources;

use App\Enums\PageArea;
use App\Filament\Resources\PageResource\Pages;
use App\Models\Page;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'heading';

    /**
     * Resolve records by slug (the model's route key).
     */
    public static function resolveRecordRouteBinding(int|string $key): ?\Illuminate\Database\Eloquent\Model
    {
        return static::getModel()::query()->where('slug', $key)->first();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Page Details')
                    ->description('Basic information about the page')
                    ->schema([
                        TextInput::make('heading')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state) {
                                if (($get('slug') ?? '') !== Str::slug($old)) {
                                    return;
                                }
                                $set('slug', Str::slug($state));
                            }),

                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->rules(['alpha_dash'])
                            ->helperText('URL-friendly version of the heading'),

                        Select::make('area')
                            ->label('Website Section')
                            ->enum(PageArea::class)
                            ->options(PageArea::class)
                            ->required()
                            ->native(false),

                        Toggle::make('navigation')
                            ->label('Show in navigation menu')
                            ->helperText('Enable to display this page in the site navigation')
                            ->default(false),

                        Textarea::make('description')
                            ->label('SEO Description')
                            ->maxLength(155)
                            ->rows(2)
                            ->helperText(fn (?string $state): string => 'Used in search results and page cards. '.strlen($state ?? '').'/155 characters'
                            )
                            ->live(),
                    ])
                    ->columns(2),

                Section::make('Heading Image')
                    ->description('The main image displayed at the top of the page')
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('heading_image')
                            ->collection('headings')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios([
                                '16:9',
                                '4:3',
                                '2:1',
                            ])
                            ->maxSize(2048)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->helperText('Recommended: 2000x1000px, max 2MB. Will be automatically resized.'),
                    ])
                    ->collapsible(),

                Section::make('Content')
                    ->description('Page content in Markdown format')
                    ->schema([
                        MarkdownEditor::make('markdown')
                            ->required()
                            ->toolbarButtons([
                                'heading',
                                'bold',
                                'italic',
                                'strike',
                                'link',
                                'bulletList',
                                'orderedList',
                                'blockquote',
                                'codeBlock',
                                'redo',
                                'undo',
                            ])
                            ->fileAttachmentsDisk('public')
                            ->fileAttachmentsDirectory('page-attachments')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('heading_image')
                    ->collection('headings')
                    ->conversion('thumbnail')
                    ->circular()
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?name=No+Image&background=e5e7eb&color=9ca3af'),

                TextColumn::make('heading')
                    ->searchable()
                    ->sortable()
                    ->limit(40),

                TextColumn::make('area')
                    ->badge()
                    ->color(fn (PageArea $state): string => match ($state) {
                        PageArea::CHRIST => 'success',
                        PageArea::CHURCH => 'info',
                        PageArea::COMMUNITY => 'warning',
                        PageArea::MEMBERS => 'danger',
                        PageArea::SERMONS => 'gray',
                    })
                    ->sortable(),

                IconColumn::make('navigation')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('gray'),

                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('area')
                    ->label('Section')
                    ->options(PageArea::class),

                TernaryFilter::make('navigation')
                    ->label('In Navigation')
                    ->placeholder('All pages')
                    ->trueLabel('In navigation')
                    ->falseLabel('Not in navigation'),
            ])
            ->actions([
                ViewAction::make()
                    ->url(fn (Page $record): string => $record->route)
                    ->openUrlInNewTab()
                    ->icon('heroicon-o-eye')
                    ->label('View'),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No pages yet')
            ->emptyStateDescription('Create your first page to get started.')
            ->emptyStateIcon('heroicon-o-document-text');
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
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['heading', 'description', 'markdown'];
    }

    public static function getGlobalSearchResultDetails(\Illuminate\Database\Eloquent\Model $record): array
    {
        /** @var Page $record */
        return [
            'Section' => $record->area->label(),
        ];
    }
}
