import React from 'react';
import { createStackNavigator } from '@react-navigation/stack';
import AuthScreen from '../screens/AuthScreen';
import OrdersScreen from '../screens/OrdersScreen';
import OrderScreen from '../screens/OrderScreen';
import ReportsScreen from '../screens/ReportsScreen';

const Stack = createStackNavigator();

const AppNavigator = () => {
  return (
    <Stack.Navigator initialRouteName="Auth">
      <Stack.Screen 
        name="Auth" 
        component={AuthScreen}
        options={{ title: 'Login' }}
      />
      <Stack.Screen 
        name="Orders" 
        component={OrdersScreen}
        options={{ title: 'Orders' }}
      />
      <Stack.Screen 
        name="OrderDetail" 
        component={OrderScreen}
        options={{ title: 'Order Details' }}
      />
      <Stack.Screen 
        name="Reports" 
        component={ReportsScreen}
        options={{ title: 'Reports' }}
      />
    </Stack.Navigator>
  );
};

export default AppNavigator;