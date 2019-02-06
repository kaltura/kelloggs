# Kelloggs!


## Developer:

### Setup:
1. Install local-web-server
``` 
npm i -g local-web-server
```

2. copy index-template.html to index.html and edit

```
   var appConfig = {
          'type': 'config',
          'config': {
              'hostUrl': 'https://localhost:8000',
              'serviceUrl': 'https://kelloggs.kaltura.com/api/',
              'jwt': 'INSERT JWT HERE',
          }
      };
      
      edit localhost port from 3000 to what npm start selected  (only if not default)
       window.document.getElementById('iframe').src = 'https://localhost:3000' + window.location.hash;

```

### Run

1. Open terminal and run `npm start` 
2. Open another terminal and run `npm run serve` 
3. if JWT expired get new one from admin console (view page source)
4. browse to  https://127.0.0.1:8000 (note the secure protocol)

### Debugging existing admin console search

1. do the search i admin console
2. copy the hash from url (for example https://admin.kaltura.com/admin_console/index.php/index/kelloggs<b>#eJyrVipOLS7OzM9TslJQMjQzNTU0NjJX0lFQSivKzw3JzE0Fi5uaWBqaWxhbGoBkSioLwKKJBZk++elumTklqUVg8XxU9ZamEPXFqUVlQBVA8bxKXaCxeSW6QK2GBoZKtQCeFyNc</b>)
3. paste the hash to the url (for example https://127.0.0.1:8000/#eJyrVipOLS7OzM9TslJQMjQzNTU0NjJX0lFQSivKzw3JzE0Fi5uaWBqaWxhbGoBkSioLwKKJBZk++elumTklqUVg8XxU9ZamEPXFqUVlQBVA8bxKXaCxeSW6QK2GBoZKtQCeFyNc)

## Troubleshooting

