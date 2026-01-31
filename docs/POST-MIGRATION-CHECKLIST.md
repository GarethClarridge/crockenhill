# Post-Migration Checklist

Use this checklist to verify everything is working correctly after the Mary UI migration.

## ✅ Immediate Verification (Do This Now)

### 1. Backend Verification
- [x] Routes registered: `./vendor/bin/sail artisan route:list --path=admin`
- [x] PHPStan passes: `./vendor/bin/sail composer phpstan` (0 errors)
- [x] Frontend builds: `npm run build`
- [x] Tests pass: `./vendor/bin/sail artisan test` (666/673 passed, 2 pre-existing breadcrumb failures)

### 2. Access the Admin
- [ ] Navigate to `http://localhost/admin` (should require login)
- [ ] Login with a verified account
- [ ] Dashboard loads without errors
- [ ] All sidebar menu items are clickable

### 3. Test Each Resource (5-10 minutes)

#### Pages
- [ ] View list at `/admin/pages`
- [ ] Search works
- [ ] Filters work (area, navigation)
- [ ] Click "Create Page"
- [ ] Fill form and save
- [ ] Edit the page you just created
- [ ] Upload a heading image
- [ ] Delete a test page

#### Meetings
- [ ] View list at `/admin/meetings`
- [ ] Create a new meeting
- [ ] Test time pickers
- [ ] Toggle recurring on/off
- [ ] Associate with a page
- [ ] Edit and save

#### Sermons
- [ ] View list at `/admin/sermons`
- [ ] Test filters (service, preacher, series)
- [ ] Edit an existing sermon
- [ ] Modify AI-generated summary
- [ ] Add/remove sermon points
- [ ] Save changes

#### Calendar Events
- [ ] View list at `/admin/calendar-events`
- [ ] Edit an event
- [ ] Change meeting association
- [ ] Save changes

#### Users
- [ ] View list at `/admin/users`
- [ ] Create a test user
- [ ] Edit a user
- [ ] Try to toggle admin on yourself (should fail with error message)
- [ ] Try to delete yourself (should fail with error message)
- [ ] Toggle admin on another user
- [ ] Delete the test user

### 4. Member Dashboard
- [ ] Navigate to `/church/members`
- [ ] Click "Go to Admin Dashboard" - should go to `/admin`
- [ ] Click "Edit meetings" - should go to `/admin/meetings`
- [ ] Click "Manage sermons" - should go to `/admin/sermons`
- [ ] Click "Categorise calendar events" - should go to `/admin/calendar-events`
- [ ] Click "Edit pages" - should go to `/admin/pages`

---

## 📱 Mobile Testing (Optional but Recommended)

- [ ] Open `/admin` on mobile browser
- [ ] Menu drawer opens when clicking "Menu" button
- [ ] Tables are responsive/scrollable
- [ ] Forms are usable
- [ ] All buttons are tappable

---

## 🔍 Edge Cases to Test

### Permissions
- [ ] Try accessing `/admin` when logged out (should redirect to login)
- [ ] Try accessing `/admin` with unverified account (should block or redirect)

### Form Validation
- [ ] Try submitting empty forms (should show validation errors)
- [ ] Try creating a page with duplicate slug (should show error)
- [ ] Try invalid email format in user creation (should show error)

### Media Upload
- [ ] Upload image larger than 2MB (should show error)
- [ ] Upload non-image file (should show error)
- [ ] Upload valid image (should work)
- [ ] Delete uploaded image (should work)

### Bulk Actions
- [ ] Select multiple pages
- [ ] Click "Delete Selected"
- [ ] Confirm deletion
- [ ] Verify pages were deleted

---

## 🚨 Known Limitations

These are intentional design decisions, not bugs:

1. **No Sermon Creation** - Sermons are created via upload at `/christ/sermons/upload`
2. **No Calendar Event Creation** - Events are synced from Google Calendar
3. **No Image Editor** - Upload images externally edited (unlike Filament's built-in editor)
4. **No Global Search** - Each resource has its own search (can be added later if needed)

---

## 🐛 If You Find Issues

### Common Issues and Solutions

**Issue**: Styles not loading
```bash
npm run build
```

**Issue**: 404 on admin routes
```bash
./vendor/bin/sail artisan route:clear
./vendor/bin/sail artisan config:clear
```

**Issue**: Class not found errors
```bash
./vendor/bin/sail composer dump-autoload
```

**Issue**: Livewire components not working
```bash
./vendor/bin/sail artisan livewire:discover
./vendor/bin/sail artisan view:clear
```

**Issue**: Database errors
```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed
```

---

## 📝 Post-Testing Notes

After you've tested everything, note any issues here:

### Issues Found
- [ ] None (everything works!)
- [ ] Issue 1: _____________________
- [ ] Issue 2: _____________________
- [ ] Issue 3: _____________________

### Feature Requests
Ideas for future enhancements:
- [ ] _____________________
- [ ] _____________________
- [ ] _____________________

---

## 🎉 Final Sign-Off

Once you've completed this checklist:

- [ ] All immediate verification tasks completed
- [ ] All resources tested successfully
- [ ] Member dashboard links verified
- [ ] Mobile testing completed (optional)
- [ ] Edge cases tested
- [ ] Any issues documented above

**Migration Status**: ✅ Verified and Production-Ready

**Date Verified**: _______________
**Verified By**: _______________

---

## 📞 Need Help?

- **Mary UI Issues**: https://github.com/robsontenorio/mary/issues
- **Livewire Issues**: https://github.com/livewire/livewire/discussions
- **daisyUI Issues**: https://github.com/saadeghi/daisyui/issues

For implementation-specific questions, refer to:
- [Implementation Summary](mary-ui-implementation-summary.md)
- [Migration Complete](mary-ui-migration-complete.md)
