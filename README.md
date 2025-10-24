# WordPress Social Media Feed Plugin

A powerful WordPress plugin that aggregates and displays social media content from multiple platforms with REST API support for mobile app integration.

## Features

- Integrates with multiple social media platforms:
  - YouTube (videos, shorts, live streams)
  - TikTok (videos, live streams)
  - Facebook (posts, videos, live streams)
  - Instagram (posts, reels, live streams)
- Real-time live streaming support
- Multiple layout options (grid, list, masonry)
- Responsive design
- REST API endpoints for mobile app integration
- JWT authentication
- Efficient caching system
- Rate limiting protection
- Customizable display options

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- [Simple JWT Login](https://wordpress.org/plugins/simple-jwt-login/) plugin
- API credentials for each platform you want to use

## Installation

1. Download the plugin zip file
2. Go to WordPress admin panel > Plugins > Add New
3. Click "Upload Plugin" and select the zip file
4. Click "Install Now" and then "Activate"
5. Install and configure the Simple JWT Login plugin
6. Go to Social Feed > Settings to configure your social media platforms

## Configuration

### API Credentials

You'll need to obtain API credentials for each platform you want to use:

#### YouTube
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Enable the YouTube Data API v3
4. Create API credentials (API Key)
5. Get your YouTube channel ID

#### TikTok
1. Register as a [TikTok Developer](https://developers.tiktok.com/)
2. Create a new app
3. Get your API key and access token

#### Facebook
1. Go to [Facebook Developers](https://developers.facebook.com/)
2. Create a new app
3. Enable the Facebook Graph API
4. Get your App ID, App Secret, and generate an access token

#### Instagram
1. Go to [Facebook Developers](https://developers.facebook.com/)
2. Create a new app or use your existing Facebook app
3. Add Instagram Basic Display
4. Configure your app and get the required credentials

### Plugin Settings

1. Go to Social Feed > Settings in your WordPress admin panel
2. Enable the platforms you want to use
3. Enter your API credentials for each platform
4. Configure display preferences and cache settings

## Usage

### Shortcodes

#### Display Social Feed
```
[social_feed platforms="youtube,tiktok" types="video,short" layout="grid" per_page="12" sort="date" order="desc"]
```

Parameters:
- `platforms`: Comma-separated list of platforms (youtube, tiktok, facebook, instagram)
- `types`: Comma-separated list of content types (video, short, post, reel, live)
- `layout`: Grid layout type (grid, list, masonry)
- `per_page`: Number of items per page
- `sort`: Sort field (date, popularity)
- `order`: Sort order (asc, desc)

#### Display Live Streams
```
[social_streams platforms="youtube,tiktok" status="live" per_page="12"]
```

Parameters:
- `platforms`: Comma-separated list of platforms
- `status`: Stream status filter (live, upcoming, ended)
- `per_page`: Number of items per page

## REST API Documentation

Base URL: `/wp-json/social-feed/v1`

### Authentication

All API endpoints require JWT authentication using the Simple JWT Login plugin. Include the JWT token in the Authorization header:

```
Authorization: Bearer your_jwt_token
```

### Error Responses

All endpoints may return the following error responses:

- `401 Unauthorized`: Missing or invalid JWT token
- `403 Forbidden`: Invalid permissions
- `500 Internal Server Error`: Server-side error

### Endpoints

#### 1. Get Combined Feed

Retrieves a combined feed of social media content from all configured platforms.

```
GET /feeds
```

Query Parameters:
- `platform[]`: (Optional) Array of platforms to filter by (youtube, tiktok)
- `type[]`: (Optional) Array of content types to filter by (video, short, live)
- `page`: (Optional) Page number, default: 1
- `per_page`: (Optional) Items per page (1-100), default: 12
- `sort`: (Optional) Sort field (date, popularity), default: date
- `order`: (Optional) Sort order (asc, desc), default: desc

Example Request:
```
GET /wp-json/social-feed/v1/feeds?platform[]=youtube&type[]=video&page=1&per_page=12&sort=date&order=desc
```

Success Response:
```json
{
    "status": "success",
    "data": [
        {
            "id": "string",
            "platform": "string",
            "type": "string",
            "title": "string",
            "description": "string",
            "thumbnail": "string",
            "url": "string",
            "published_at": "datetime",
            "stats": {
                "views": "number",
                "likes": "number"
            }
        }
    ]
}
```

#### 2. Get Live Streams

Retrieves live streams from all configured platforms.

```
GET /live-streams
```

Query Parameters:
- `platform[]`: (Optional) Array of platforms to filter by (youtube, tiktok)
- `status`: (Optional) Stream status (live, upcoming, ended)
- `page`: (Optional) Page number, default: 1
- `per_page`: (Optional) Items per page (1-100), default: 12

Example Request:
```
GET /wp-json/social-feed/v1/live-streams?platform[]=youtube&status=live&page=1&per_page=12
```

Success Response:
```json
{
    "status": "success",
    "data": [
        {
            "id": "string",
            "platform": "string",
            "title": "string",
            "description": "string",
            "thumbnail": "string",
            "url": "string",
            "status": "string",
            "start_time": "datetime",
            "viewer_count": "number"
        }
    ]
}
```

## Mobile App Integration

1. Install and configure the Simple JWT Login plugin
2. Generate JWT tokens for your mobile app users
3. Use the REST API endpoints to fetch social media content
4. Implement proper error handling and rate limiting in your app

## Performance Optimization

The plugin includes several performance optimizations:

- Efficient caching system for API responses
- Rate limiting protection
- Lazy loading of media content
- Pagination support
- Optimized database queries

## Security

- API credentials are stored securely using WordPress encryption
- Input validation and sanitization
- CSRF protection
- XSS protection
- Rate limiting
- JWT authentication for API access

## Support

For bug reports and feature requests, please use the GitHub issue tracker.

## License

This plugin is licensed under the GPL v2 or later.

## Credits

- [Simple JWT Login](https://wordpress.org/plugins/simple-jwt-login/) for JWT authentication
- [Masonry](https://masonry.desandro.com/) for grid layout
- Social media platform APIs 