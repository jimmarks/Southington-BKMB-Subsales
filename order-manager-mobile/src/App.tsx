import React from 'react';
import { NavigationContainer } from '@react-navigation/native';
import { createStackNavigator } from '@react-navigation/stack';
import AuthScreen from './screens/AuthScreen';
import OrdersScreen from './screens/OrdersScreen';
import OrderScreen from './screens/OrderScreen';
import MapScreen from './screens/MapScreen';
import ReportsScreen from './screens/ReportsScreen';
import { Provider } from 'react-redux';
import store from './store';

const Stack = createStackNavigator();

const App = () => {
  return (
    <Provider store={store}>
      <NavigationContainer>
        <Stack.Navigator initialRouteName="Auth">
          <Stack.Screen name="Auth" component={AuthScreen} />
          <Stack.Screen name="Orders" component={OrdersScreen} />
          <Stack.Screen name="Order" component={OrderScreen} />
          <Stack.Screen name="Map" component={MapScreen} />
          <Stack.Screen name="Reports" component={ReportsScreen} />
        </Stack.Navigator>
      </NavigationContainer>
    </Provider>
  );
};

export default App;