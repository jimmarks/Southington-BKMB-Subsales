import React from 'react';
import { View, Text, StyleSheet } from 'react-native';

const MinimalApp = () => {
  return (
    <View style={styles.container}>
      <Text style={styles.text}>HELLO WORLD</Text>
      <Text style={styles.text}>React Native is Working!</Text>
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: 'red',
  },
  text: {
    fontSize: 30,
    fontWeight: 'bold',
    color: 'white',
    textAlign: 'center',
  },
});

export default MinimalApp;