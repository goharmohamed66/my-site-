<?php
// /api/google-config.php — Google OAuth + Picker credentials.
// Used by google-oauth-start.php, google-callback.php and the front-end
// to launch the Drive picker.
const GOOGLE_CLIENT_ID     = '869100247874-lped0npbaq5dl0lgo4lvo5953tmqr20j.apps.googleusercontent.com';
const GOOGLE_CLIENT_SECRET = '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';
const GOOGLE_API_KEY       = 'AIzaSyDmmkwik1u_oWBaPXRe2qHsydupjQWFrBY';
const GOOGLE_PROJECT_NUMBER = '869100247874';
const GOOGLE_REDIRECT_URI  = 'https://indigo-dog-836598.hostingersite.com/api/google-callback.php';
// Full Drive scope so the app can create brand/product folders, copy
// templates, and upload files — not just list/read. The user identity
// scopes (openid/email/profile) label the connector with the real account.
const GOOGLE_SCOPES = 'openid email profile https://www.googleapis.com/auth/drive';

// Permission scope: only allow tools to browse INSIDE this Drive folder.
// User can override per-connector via /api/google-drive.php?action=set_root.
const GOOGLE_DEFAULT_ROOT_FOLDER = '1wp5QITbszialeM4AC46Gz3T4k3rS5zYf'; // "Clients Data"
