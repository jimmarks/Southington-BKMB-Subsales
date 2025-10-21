import React from 'react';
import { View, Text, StyleSheet } from 'react-native';

const TestApp = () => {
  return (
    <View style={styles.container}>
      <Text style={styles.text}>Test App - React Native is Working!</Text>
      <Text style={styles.subtext}>If you see this, the basic setup is correct.</Text>
    </View>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#f0f0f0',
    padding: 20,
  },
  text: {
    fontSize: 24,
    fontWeight: 'bold',
    color: '#333',
    textAlign: 'center',
    marginBottom: 16,
  },
  subtext: {
    fontSize: 16,
    color: '#666',
    textAlign: 'center',
  },
});

export default TestApp;