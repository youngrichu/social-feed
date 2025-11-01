# WordPress Social Media Feed Plugin

A powerful WordPress plugin that aggregates and displays social media content from multiple platforms with REST API support for mobile app integration.

## Features

- **Multi-platform integration**: YouTube, TikTok, Facebook, Instagram
- **Multiple content types**: Videos, shorts, posts, reels, live streams
- **Flexible layouts**: Grid, list, masonry with responsive design
- **REST API**: Complete endpoints for mobile app integration
- **Push notifications**: Real-time notifications via Church App Notifications
- **Performance optimized**: Advanced caching and quota management
- **Admin dashboard**: Consolidated settings with performance monitoring
- **Shortcode support**: Easy frontend integration

## Requirements

- WordPress 5.8+
- PHP 7.4+
- [Church App Notifications](https://github.com/church-app/notifications) plugin
- API credentials for desired platforms

## Installation

1. Download and install the plugin
2. Install and activate Church App Notifications plugin
3. Go to **Social Feed > Settings** in WordPress admin
4. Configure your social media platform API credentials
5. Set up notification preferences

## Usage

### Shortcodes

Display social media feed:
```php
[social_feed platforms="youtube,tiktok" types="video,short" layout="grid" per_page="12"]
```

Display live streams:
```php
[social_streams platforms="youtube" status="live" per_page="6"]
```

### Parameters

| Parameter | Options | Description |
|-----------|---------|-------------|
| `platforms` | `youtube,tiktok,facebook,instagram` | Comma-separated platforms |
| `types` | `video,short,post,reel,live` | Content types to display |
| `layout` | `grid,list,masonry` | Display layout |
| `per_page` | `1-100` | Items per page |
| `sort` | `date,popularity` | Sort criteria |
| `order` | `asc,desc` | Sort order |

## REST API

Base URL: `/wp-json/social-feed/v1`

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/feeds` | GET | Get combined social media feed |
| `/live-streams` | GET | Get live streams |
| `/notifications/register` | POST | Register device for push notifications |
| `/notifications/unregister` | POST | Unregister device |

### Example Request
```bash
GET /wp-json/social-feed/v1/feeds?platform[]=youtube&type[]=video&per_page=12
```

### Example Response
```json
{
  "status": "success",
  "data": [
    {
      "id": "video_id",
      "platform": "youtube",
      "type": "video",
      "title": "Video Title",
      "thumbnail": "https://...",
      "url": "https://...",
      "published_at": "2024-01-01T00:00:00Z",
      "stats": {
        "views": 1000,
        "likes": 50
      }
    }
  ]
}
```

## Configuration

### API Credentials

1. **YouTube**: Get API key from [Google Cloud Console](https://console.cloud.google.com/)
2. **TikTok**: Register at [TikTok Developers](https://developers.tiktok.com/)
3. **Facebook/Instagram**: Create app at [Facebook Developers](https://developers.facebook.com/)

### Settings

Navigate to **Social Feed > Settings** and configure:
- **Platforms**: Enable platforms and add API credentials
- **Shortcodes**: Set default display options
- **Performance**: Configure caching and optimization
- **Notifications**: Manage push notification settings

## Mobile Integration

Register device for push notifications:
```javascript
// Register device token
fetch('/wp-json/social-feed/v1/notifications/register', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    user_id: 123,
    device_token: 'expo_push_token',
    platform: 'ios',
    notification_types: ['video', 'live']
  })
});
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests for new functionality
5. Submit a pull request

## License

GPL v2 or later

## Support

For issues and feature requests, please use the GitHub issue tracker.