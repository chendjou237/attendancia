<?php

namespace App\Filament\Resources\Teachers;

use App\Filament\Resources\Teachers\Pages\CreateTeacher;
use App\Filament\Resources\Teachers\Pages\EditTeacher;
use App\Filament\Resources\Teachers\Pages\ListTeachers;
use App\Filament\Resources\Teachers\Pages\ManageTimetable;
use App\Filament\Resources\Teachers\Schemas\TeacherForm;
use App\Filament\Resources\Teachers\Tables\TeachersTable;
use App\Models\Teacher;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Onboarding §1/§2: Admin owns the Teacher record itself (add, edit,
 * deactivate); Officer only reaches a teacher to use Manage Timetable,
 * so viewAny is shared but mutation of the teacher's own fields stays
 * admin-only.
 */
class TeacherResource extends Resource
{
    protected static ?string $model = Teacher::class;

    protected static ?string $recordTitleAttribute = 'full_name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return TeacherForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TeachersTable::configure($table);
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
            'index' => ListTeachers::route('/'),
            'create' => CreateTeacher::route('/create'),
            'edit' => EditTeacher::route('/{record}/edit'),
            'timetable' => ManageTimetable::route('/{record}/timetable'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'officer']) ?? false;
    }

    /**
     * Overriding the Response-returning methods (rather than the plain
     * canCreate()/canEdit()/canDelete() booleans) matters here: Filament's
     * standard action buttons (CreateAction/EditAction/DeleteBulkAction)
     * resolve their visibility through these Get*AuthorizationResponse
     * methods, not through the boolean can*() helpers.
     */
    public static function getCreateAuthorizationResponse(): Response
    {
        return static::isAdmin() ? Response::allow() : Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return static::isAdmin() ? Response::allow() : Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return static::isAdmin() ? Response::allow() : Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return static::isAdmin() ? Response::allow() : Response::deny();
    }

    private static function isAdmin(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function getModelLabel(): string
    {
        return __('panel.nav.teachers.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('panel.nav.teachers.plural');
    }
}
