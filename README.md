<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

# Circuitil - Influencer & Brand Collaboration Platform API (Laravel)

A Laravel-based REST API for an Android app that connects influencers and brands, enabling collaboration discovery, real-time chat (Pusher), and push notifications (Firebase FCM).

## Tech Stack
- Backend: PHP8 (Laravel 10)
- Database: MySQL
- Real-time: Pusher (WebSockets for chat)
- Push Notifications: Firebase Cloud Messaging (FCM HTTP v1 API)
- Auth: Laravel Sanctum (API tokens)
- Social Auth: Google OAuth, Instagram OAuth

## Core Features

### Authentication
- Register with OTP verification (send/verify/resend)
- Login (email/password)
- Social login (Google OAuth, Instagram OAuth)
- Password reset (OTP-based)
- Account deletion (OTP-verified)
- Logout

### User Profiles
- Dual user types: **Influencers** and **Brands**
- Profile management (update, delete, view)
- Category-based filtering
- Trending and top-collaborated influencers
- Top brands by collaboration count

### Collaboration Management
- Brands create collaborations (with budget, category, description)
- Influencers can:
  - Browse/search collaborations
  - Mark interest in collaborations
  - Accept/decline brand invitations
- Brands can:
  - Invite influencers
  - View interested influencers
  - Accept influencers for collaborations
  - Complete/close collaborations
- Collaboration requests lifecycle (pending → accepted → completed)

### Real-time Chat (Pusher)
- One-on-one messaging between brands and influencers
- Send/reply to messages
- Fetch chat history
- Mark messages as read
- Delete messages
- Real-time broadcast using Pusher channels (`chat-{chatId}`)
- Events: `MessageSent`, `ChatUpdated`

### Social Features
- Posts (create, view, like, delete)
- Likes count per post
- User feed and explore

### Notifications
- Push notifications via Firebase FCM (HTTP v1 API with OAuth 2.0)
- In-app notifications feed

### User Actions
- Block/unblock users
- Report users/posts

### App Version Management
- Fetch current/previous versions (platform-specific: Android)
- Create new app versions (for update prompts)

## Folder Structure
- `app/Http/Controllers` : API controllers (Auth, Brand, Influencer, Collaboration, Chat, Post, etc.)
- `app/Models` : Eloquent models
- `app/Services` : Business logic (PushNotificationService, etc.)
- `app/Events` : Broadcast events (MessageSent, ChatUpdated)
- `app/Observers` : Model observers (InfluencerObserver for auto-matching collaborations)
- `routes/api.php` : API routes

## Requirements
- PHP 8.x
- Composer
- MySQL
- Pusher account (for real-time chat)
- Firebase project (for push notifications)
- Google Cloud Console project (for Google/Instagram OAuth)

## Environment Variables
Create `.env` in the project root:

```env
# App
APP_NAME=Circuitil
APP_ENV=local
APP_KEY=
APP_URL=http://localhost

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=circuitil
DB_USERNAME=root
DB_PASSWORD=

# Pusher (Real-time Chat)
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=

# Firebase (Push Notifications)
FIREBASE_SERVICE_ACCOUNT=/path/to/firebase-service-account.json

# Google OAuth
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=

# Instagram OAuth
INSTAGRAM_CLIENT_ID=
INSTAGRAM_CLIENT_SECRET=
INSTAGRAM_REDIRECT_URI=
```

## Real-time Chat Setup (Pusher)
- Create a Pusher app at pusher.com
- Add credentials to .env (PUSHER_APP_ID, PUSHER_APP_KEY, PUSHER_APP_SECRET, PUSHER_APP_CLUSTER)
- Events broadcast to chat-{chatId} channels

## Push Notifications Setup (Firebase FCM)
- Create a Firebase project
- Download service account JSON from Firebase Console
- Set path in .env: FIREBASE_SERVICE_ACCOUNT=/path/to/json
- Service uses OAuth 2.0 to get access tokens for FCM HTTP v1 API

## API Reference (Postman)

Import the Postman collection to test all available APIs:

- **Collection:** [Circuitil API Postman Collection](./docs/postman/Circuitil.postman_collection.json)
