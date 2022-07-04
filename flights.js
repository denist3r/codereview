const map = L.map('flights-map').setView([26.2697909, 50.6260439], 4);
const attributtion = 'Powered by denist3r';
const tileUrl = 'https://tile.thunderforest.com/cycle/{z}/{x}/{y}.png?apikey=0ef0c8808f134abd8c5ffef75659c4d9';
const tiles = L.tileLayer(tileUrl, {attributtion, maxZoom: 12, minZoom: 4});
tiles.addTo(map);
const api_url = 'https://denist3r.github.io/flights-data/DATA/realtime.json';


let planeIcon = L.icon({
  iconUrl: 'https://denist3r.github.io/flights-data/DATA/plane-solid.svg',
  iconSize:     [20, 60],
  shadowSize:   [50, 64],
  iconAnchor:   [22, 94],
  shadowAnchor: [4, 62],
  popupAnchor:  [-10, -80]
});


async function getFlights() {
  const response = await fetch(api_url);
  let data = await response.json();
  for (let i = 0; i < data.planes.length; i++) {
    let {origin, time, airline_logo, status, longitude, latitude, direction} = data.planes[i];
    let customPopup =
      `<img width= 50 src= "${airline_logo}">`
      + '<h3>' + "Destination: " + origin + '</h3>'
      + '<p>' + "Estimated Time: " + `<span class="time">` + time + `</span>` + '</p>'
      + '<div class="status">' + status + '</div>';

    const marker = L.marker([latitude, longitude])
      .bindPopup(customPopup, {keepInView: true})
      .addTo(map);
    marker.setIcon(planeIcon);
    marker.setRotationAngle(direction);

    let movePlanes = function() {
      if (direction <= 50) {
        latitude = parseFloat(latitude) - 0.001;
        longitude = parseFloat(longitude) + 0.001;
      } else if (direction <= 100) {
        latitude = parseFloat(latitude) - 0.001;
        longitude = parseFloat(longitude) + 0.001;
      } else if (direction <= 150) {
        latitude = parseFloat(latitude) - 0.001;
        longitude = parseFloat(longitude) - 0.001;
      } else if (direction <= 180) {
        latitude = parseFloat(latitude) + 0.001;
        longitude = parseFloat(longitude) - 0.001;
      }

      function changeLocation() {
        marker.setLatLng([latitude, longitude]);
      }
      changeLocation();
    };
    setTimeout(movePlanes, 0);
    setInterval(movePlanes, 1000);
  }

}

getFlights();
