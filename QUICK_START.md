# Quick Start Guide - Fix "Failed to fetch" Error

## The Problem
"Failed to fetch" error means the frontend cannot connect to the Laravel backend.

## Solution: Start the Backend Server

### Step 1: Open Terminal/Command Prompt
Navigate to the project directory:
```bash
cd ebill-payment-platform
```

### Step 2: Start Laravel Backend
```bash
php artisan serve
```

You should see:
```
INFO  Server running on [http://127.0.0.1:8000]
```

**Keep this terminal window open!** The server must be running for the frontend to work.

### Step 3: Start Frontend (in a NEW terminal)
Open a new terminal window and run:
```bash
cd ebill-payment-platform/frontend
npm run dev
```

You should see:
```
  VITE v5.x.x  ready in xxx ms

  ➜  Local:   http://localhost:8080/
```

### Step 4: Test Connection
1. Open your browser
2. Go to: http://localhost:8080/check-backend-connection.html
3. Click "Run Connection Tests"
4. All tests should pass ✅

### Step 5: Try Login Again
Now go to: http://localhost:8080/login
The login should work!

## Troubleshooting

### Backend won't start?
- Make sure PHP is installed: `php --version`
- Check if port 8000 is in use
- Try: `php artisan serve --port=8001` (then update frontend .env)

### Still getting "Failed to fetch"?
1. **Check backend is running:**
   - Visit: http://localhost:8000
   - You should see Laravel welcome page or JSON response

2. **Check API URL:**
   - Frontend expects: `http://localhost:8000/api`
   - Verify in browser console what URL it's trying

3. **Check CORS:**
   - Open browser DevTools (F12)
   - Go to Network tab
   - Try login again
   - Look for CORS errors (red requests)

4. **Check .env files:**
   - Backend `.env`: Should have `APP_URL=http://localhost:8000`
   - Frontend `.env` (if exists): Should have `VITE_API_BASE_URL=http://localhost:8000/api`

### Common Issues

**"Connection refused"**
- Backend server is not running
- Solution: Run `php artisan serve`

**CORS error**
- Backend CORS config doesn't allow frontend origin
- Solution: Check `config/cors.php` includes `http://localhost:8080`

**"404 Not Found"**
- API routes not registered
- Solution: Check `routes/api.php` has login route

**"500 Internal Server Error"**
- Backend has an error
- Solution: Check Laravel logs in `storage/logs/laravel.log`

## Quick Checklist

- [ ] Backend running (`php artisan serve`)
- [ ] Frontend running (`npm run dev`)
- [ ] Backend accessible at http://localhost:8000
- [ ] Frontend accessible at http://localhost:8080
- [ ] No CORS errors in browser console
- [ ] Database configured (if using MySQL)

## Still Having Issues?

1. Open browser DevTools (F12)
2. Go to Console tab
3. Try login
4. Copy the exact error message
5. Check Network tab for failed requests
6. Share the error details for help

